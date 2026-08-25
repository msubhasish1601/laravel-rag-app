<?php
namespace App\Services;

use App\Models\DocumentChunk;
use App\Models\RagDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Distance;

class RagService
{
    /**
     * Calls local Ollama to convert text into a 384-dimension vector array.
     */
    protected function generateEmbedding(string $text): ?array
    {
        try {
            $response = Http::timeout(0)->post('http://localhost:11434/api/embed', [
                'model' => 'all-minilm',
                'input' => $text,
            ]);

            if ($response->successful()) {
                // The API returns an array of embeddings; we grab the first one
                return $response->json('embeddings.0');
            }
        } catch (\Exception $e) {
            Log::error('Ollama Embedding Error: ' . $e->getMessage());
        }

        return null;
    }

    public function processDocument(string $title, string $category, string $filePath, string $rawText): RagDocument
    {
        // 1. Create document record
        $document = RagDocument::create([
            'title'     => $title,
            'file_path' => $filePath,
            'category'  => $category,
        ]);

        // 2. Chunk text into manageable pieces (~1000 characters with 200 char overlap)
        $chunks = $this->chunkText($rawText, 1000, 200);

        foreach ($chunks as $index => $chunkText) {

            // 1. Generate the math vector for this specific chunk of text
            $embeddingArray = $this->generateEmbedding($chunkText);

            // 2. Save both the text AND the vector to PostgreSQL
            DocumentChunk::create([
                'rag_document_id' => $document->id,
                'chunk_index'     => $index,
                'content'         => $chunkText,
                'embedding'       => $embeddingArray, // The pgvector cast handles this array automatically!
            ]);
        }

        return $document;
    }

    protected function chunkText(string $text, int $size, int $overlap): array
    {
        $length = mb_strlen($text);
        $chunks = [];
        $start  = 0;

        while ($start < $length) {
            $chunks[]  = mb_substr($text, $start, $size);
            $start    += ($size - $overlap);
        }

        return $chunks;
    }
    public function getContextForQuery(string $query): array
    {
        $queryEmbedding = $this->generateEmbedding($query);
        $matches        = collect();

        if ($queryEmbedding) {
            $matches = DocumentChunk::with('document')
                ->whereNotNull('embedding')
                ->nearestNeighbors('embedding', $queryEmbedding, Distance::Cosine)
                ->limit(4)
                ->get();
        }

        $contextText = $matches->pluck('content')->implode("\n\n---\n\n");
        $sources     = $matches->pluck('document.title')->filter()->unique()->values()->all();

        return [
            'context' => $contextText,
            'sources' => $sources,
            'chunks'  => $matches->map(function ($chunk) {
                return [
                    'id'          => $chunk->id,
                    'document'    => $chunk->document->title ?? 'Unknown',
                    'chunk_index' => $chunk->chunk_index,
                    'content'     => $chunk->content,
                ];
            })->toArray(),
        ];
    }

    // public function getContextForQuery(string $query): array
    // {
    //     // 1. Remove all punctuation (e.g., "?" or ".") so "oops?" becomes "oops"
    //     $cleanQuery = preg_replace('/[^\w\s]/', '', strtolower($query));

    //     // 2. Expanded stop words to ignore conversational filler words
    //     $stopWords = [
    //         'what', 'is', 'in', 'the', 'a', 'an', 'and', 'to', 'of', 'how',
    //         'does', 'do', 'can', 'you', 'give', 'me', 'an', 'example', 'for', 'python', 'about',
    //     ];

    //     $words = array_filter(explode(' ', $cleanQuery), function ($word) use ($stopWords) {
    //         return mb_strlen($word) > 1 && ! in_array($word, $stopWords);
    //     });

    //     $matches = collect();

    //     if (! empty($words)) {
    //         $queryBuilder = DocumentChunk::with('document');
    //         $queryBuilder->where(function ($q) use ($words) {
    //             foreach ($words as $word) {
    //                 $q->orWhere('content', 'LIKE', '%' . $word . '%');
    //             }
    //         });
    //         // Grab top 5 matching chunks to ensure we get a wide context window
    //         $matches = $queryBuilder->limit(5)->get();
    //     }

    //     // Fallback to latest chunks if no keywords matched
    //     if ($matches->isEmpty()) {
    //         $matches = DocumentChunk::with('document')->latest()->limit(3)->get();
    //     }

    //     $contextText = $matches->pluck('content')->implode("\n\n---\n\n");
    //     $sources     = $matches->pluck('document.title')->unique()->values()->all();

    //     return [
    //         'context' => $contextText,
    //         'sources' => $sources,
    //     ];
    // }
}
