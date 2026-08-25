<?php
namespace App\Http\Controllers;

use App\Models\RagDocument;
use App\Models\RagMessage;
use App\Services\RagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RagDocumentController extends Controller
{
    protected RagService $ragService;

    public function __construct(RagService $ragService)
    {
        $this->ragService = $ragService;
    }
    // Show main UI view
    public function index()
    {
        $documents = RagDocument::all();
        $messages  = RagMessage::orderBy('created_at', 'asc')->get();
        return view('rag.index', compact('documents', 'messages'));
    }

    // Handle document upload and chunking
    public function store(Request $request)
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf,csv,txt,md|max:4096',
            'category' => 'required|string|max:50',
        ]);

        $file      = $request->file('document');
        $path      = $file->store('documents', 'public');
        $extension = $file->getClientOriginalExtension();

        $rawText = '';

        if (strtolower($extension) === 'pdf') {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf    = $parser->parseFile($file->getRealPath());
                $pages  = $pdf->getPages();
                foreach ($pages as $page) {
                    $rawText .= $page->getText() . "\n";
                }
            } catch (\Exception $e) {
                return back()->withErrors(['document' => 'Failed to parse PDF: ' . $e->getMessage()]);
            }
        } else {
            $rawText = file_get_contents($file->getRealPath());
        }

        if (trim($rawText) === '') {
            return back()->withErrors(['document' => 'Could not extract text from this file.']);
        }

        $this->ragService->processDocument($file->getClientOriginalName(), $request->input('category'), $path, $rawText);

        return back()->with('success', 'Document uploaded and indexed successfully!');
    }

    // Handle chat queries via Ollama
    public function chat(Request $request)
    {
        set_time_limit(0);

        $request->validate([
            'question' => 'required|string',
            'ai_model' => 'required|in:ollama,gemini',
            'category' => 'nullable|string',
        ]);

        $question = $request->input('question');
        $aiModel  = $request->input('ai_model');
        $category = $request->input('category', 'All');

        // 1. Save user question
        RagMessage::create(['role' => 'user', 'content' => $question]);

        // 2. Retrieve vector context using metadata filter
        $retrieval = $this->ragService->getContextForQuery($question, $category);
        $context   = $retrieval['context'];
        $rawChunks = $retrieval['chunks'] ?? [];

        // 3. Context-Proportional Token Sizing (Industry Standard)
        // Automatically scales token ceilings based on the volume of retrieved text
        $contextLength = strlen($context);
        if ($contextLength > 2000) {
            $maxTokens = 2048; // Comprehensive output (e.g., full recipes, guides)
        } elseif ($contextLength > 800) {
            $maxTokens = 1024; // Standard balanced output
        } else {
            $maxTokens = 512; // Concise definition/answer
        }

        $systemPrompt = "You are a precise document-grounded retrieval assistant. Answer the user's question using ONLY the provided context. If the answer cannot be found in the context, state clearly that the uploaded documents do not contain enough information to answer.";
        $prompt       = "Context:\n{$context}\n\nQuestion: {$question}";

        $answer = '';

        if ($aiModel === 'gemini') {
            $apiKey   = env('GEMINI_API_KEY');
            $response = Http::timeout(30)
                ->withoutVerifying()
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'Content-Type'   => 'application/json',
                ])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent", [
                    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents'           => [['parts' => [['text' => $prompt]]]],
                    'generationConfig'   => ['maxOutputTokens' => $maxTokens],
                ]);

            $answer = $response->successful()
                ? ($response->json('candidates.0.content.parts.0.text') ?? 'No response generated.')
                : ('Gemini API Error: ' . $response->json('error.message', $response->body()));
        } else {
            // Ollama Local with Context-Proportional Options
            $response = Http::timeout(0)->post('http://localhost:11434/api/generate', [
                'model'  => 'phi3:mini',
                'prompt' => "{$systemPrompt}\n\n{$prompt}",
                'stream'     => false,
                'keep_alive' => '10m',
                'options'    => ['num_predict' => $maxTokens],
            ]);

            $answer = $response->json('response') ?? 'Unable to generate response from Ollama.';
        }

        // 4. Post-Generation Source Filtering & Refusal Check
        $activeSources = [];

        // Detect if the AI generated a standard "not found" refusal message
        $isRefusalResponse = preg_match('/(do not contain|cannot be found|not enough information|does not contain|no information)/i', $answer);

        // Only look for sources if the model actually found an answer in the text
        if (! $isRefusalResponse) {
            foreach ($rawChunks as $chunk) {
                $docTitle = $chunk['document'];
                if (str_contains(strtolower($answer), strtolower(pathinfo($docTitle, PATHINFO_FILENAME))) || count($retrieval['sources']) === 1) {
                    if (! in_array($docTitle, $activeSources)) {
                        $activeSources[] = $docTitle;
                    }
                }
            }

            if (empty($activeSources) && ! empty($retrieval['sources'])) {
                $activeSources[] = $retrieval['sources'][0];
            }
        }

        $filteredSources = implode(', ', $activeSources);
        $sourceInfo      = ! empty($filteredSources) ? "Source(s): {$filteredSources}" : null;

        // 5. Save AI Message to DB
        RagMessage::create([
            'role'        => 'assistant',
            'content'     => $answer,
            'source_info' => $sourceInfo,
            'raw_chunks'  => $rawChunks,
        ]);

        return response()->json([
            'answer'     => $answer,
            'source'     => $sourceInfo,
            'raw_chunks' => $rawChunks,
        ]);
    }
    // Clear chat history
    public function clearChat()
    {
        RagMessage::truncate();
        return back()->with('success', 'Chat history cleared.');
    }

    // Delete a specific document
    public function destroy($id)
    {
        $document = RagDocument::findOrFail($id);
        $document->delete();
        return back()->with('success', 'Document deleted successfully.');
    }
}
