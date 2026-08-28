<?php
namespace App\Jobs;

use App\Models\RagDocument;
use App\Services\RagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRagDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $documentId;
    public $rawText;
    public $timeout = 300;

    public function __construct($documentId, $rawText)
    {
        $this->documentId = $documentId;
        $this->rawText    = $rawText;
    }

    public function handle(RagService $ragService)
    {
        $document = RagDocument::find($this->documentId);

        if (! $document) {
            return;
        }

        try {
            // Note: You will need to update your RagService to have a method
            // that ONLY chunks the text and attaches it to an EXISTING document.
            $ragService->chunkExistingDocument($document, $this->rawText);

            // Success! Update status to ready
            $document->update(['status' => 'ready']);

        } catch (\Exception $e) {
            // Failure! Mark as failed so the user knows something went wrong
            $document->update(['status' => 'failed']);
            Log::error("Failed to process RAG Document: " . $e->getMessage());
            throw $e;
        }
    }
}
