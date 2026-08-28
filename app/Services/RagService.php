<?php
namespace App\Services;

use App\Models\DocumentChunk;
use App\Models\RagDocument;
use DB;
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
                'search_vector'   => DB::raw("to_tsvector('english', " . DB::getPdo()->quote($chunkText) . ")"),
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

    public function getContextForQuery(string $query, ?string $category = null): array
    {
        $queryEmbedding = $this->generateEmbedding($query);
        $matches        = collect();

        if ($queryEmbedding) {
            // 1. DENSE SEARCH (Vector Cosine Similarity - PRIMARY AUTHORITY)
            $vectorQuery = DocumentChunk::with('document')
                ->whereNotNull('embedding');

            if ($category && $category !== 'All') {
                $vectorQuery->whereHas('document', function ($q) use ($category) {
                    $q->where('category', $category);
                });
            }

            // Give vector search a higher allocation (e.g., top 6)
            $vectorMatches = (clone $vectorQuery)
                ->nearestNeighbors('embedding', $queryEmbedding, Distance::Cosine)
                ->limit(6)
                ->get();

            // 2. SPARSE SEARCH (PostgreSQL Full-Text Keyword Search - SECONDARY BOOST)
            $keywordQuery = DocumentChunk::with('document')
                ->whereNotNull('search_vector');

            if ($category && $category !== 'All') {
                $keywordQuery->whereHas('document', function ($q) use ($category) {
                    $q->where('category', $category);
                });
            }

            // Keep keyword limit smaller (e.g., top 2) so it doesn't flood the context with headers
            $keywordMatches = $keywordQuery
                ->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$query])
                ->limit(2)
                ->get();

            // 3. PRIORITIZED FUSION
            // Put vector matches FIRST so semantic context anchors the prompt,
            // then append unique keyword matches to catch exact terms without displacing steps.
            $matches = $vectorMatches->concat($keywordMatches)->unique('id')->take(6);
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

    /**
     * Processes text for a document that ALREADY exists in the database.
     */
    public function chunkExistingDocument(\App\Models\RagDocument $document, string $rawText)
    {
        // 1. Chunk the text
        $chunks = explode("\n\n", $rawText);
        $chunks = array_filter(array_map('trim', $chunks));

        $chunkIndex = 0; // <--- 1. Initialize a counter here

        foreach ($chunks as $chunkText) {
            if (empty($chunkText) || strlen($chunkText) < 10) {
                continue;
            }

            // 2. Generate Vector Embedding
            $embedding = $this->generateEmbedding($chunkText);

            // 3. Save the chunk
            \App\Models\DocumentChunk::create([
                'rag_document_id' => $document->id,
                'chunk_index'     => $chunkIndex, // <--- 2. Add this field!
                'content'         => $chunkText,
                'embedding'       => $embedding,
            ]);

            $chunkIndex++; // <--- 3. Increment the counter for the next chunk
        }
    }
}
