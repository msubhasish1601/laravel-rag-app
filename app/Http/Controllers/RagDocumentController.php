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

        // 1. PRUNED SLIDING WINDOW (Conversational Short-Term Memory)
        // Pull the last 4 messages (2 conversation turns) prior to saving the new message
        $recentHistory = RagMessage::orderBy('id', 'desc')->take(4)->get()->reverse();

        $historyText = "";
        if ($recentHistory->isNotEmpty()) {
            $historyText = "--- RECENT CONVERSATION HISTORY ---\n";
            foreach ($recentHistory as $msg) {
                $role  = $msg->role === 'user' ? 'User' : 'AI';
                // Truncate assistant responses to 250 characters to preserve token budget
                $content      = \Illuminate\Support\Str::limit($msg->content, 250, '...');
                $historyText .= "{$role}: {$content}\n";
            }
            $historyText .= "-----------------------------------\n\n";
        }

        // 2. Save user question
        RagMessage::create(['role' => 'user', 'content' => $question]);

        // 3. Retrieve context using Hybrid Search & Metadata Filter
        $retrieval = $this->ragService->getContextForQuery($question, $category);
        $context   = $retrieval['context'];
        $rawChunks = $retrieval['chunks'] ?? [];

        // 4. Context-Proportional Token Sizing
        $contextLength = strlen($context);
        if ($contextLength > 2000) {
            $maxTokens = 2048; // Comprehensive output (e.g., full recipes, guides)
        } elseif ($contextLength > 800) {
            $maxTokens = 1024; // Standard balanced output
        } else {
            $maxTokens = 512; // Concise definition/answer
        }

        // 5. INTENT-AWARE SYSTEM PROMPT (Task-Specific Extraction)
        $systemPrompt = "You are a precise, document-grounded AI assistant. Your primary directive is to answer the user's EXACT question using ONLY the provided context and recent conversation history.

        Follow these strict formatting rules based on what the user asks:
        1. Definition Queries (e.g., 'What is X?'): Provide a concise summary explaining what the item is. Do NOT include full recipes, steps, or exhaustive ingredient lists unless explicitly requested.
        2. Ingredient/Component Queries (e.g., 'What are the ingredients in X?', 'What are its ingredients?'): Output ONLY the bulleted list of ingredients/components. Do NOT include preparation steps or cooking directions.
        3. Procedural Queries (e.g., 'How to prepare X', 'Recipe for X', 'Steps for X'): Provide the COMPLETE, exhaustive step-by-step instructions and ingredients from the context without summarizing or skipping steps.

        Use the recent conversation history strictly to resolve pronouns (e.g., 'it', 'they', 'this recipe') or references to prior topics. Do not volunteer extra unrequested sections. If the answer cannot be found in the context, state clearly that the uploaded documents do not contain enough information to answer.";

        // Assemble final prompt
        $prompt = "{$historyText}Context:\n{$context}\n\nCurrent Question: {$question}";

        $answer = '';

        // 6. Model Execution
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
            // Ollama Local
            $response = Http::timeout(0)->post('http://localhost:11434/api/generate', [
                'model'  => 'phi3:mini',
                'prompt' => "{$systemPrompt}\n\n{$prompt}",
                'stream'     => false,
                'keep_alive' => '10m',
                'options'    => ['num_predict' => $maxTokens],
            ]);

            $answer = $response->json('response') ?? 'Unable to generate response from Ollama.';
        }

        // 7. Post-Generation Source Filtering & Refusal Check
        $activeSources     = [];
        $isRefusalResponse = preg_match('/(do not contain|cannot be found|not enough information|does not contain|no information)/i', $answer);

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

        // 8. Save Assistant Message to DB
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
