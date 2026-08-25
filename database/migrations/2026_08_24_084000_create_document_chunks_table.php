<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();

            // Explicitly specify 'documents' table name inside constrained()
            $table->foreignId('rag_document_id')->constrained('rag_documents')->cascadeOnDelete();

            $table->integer('chunk_index');
            $table->text('content');
            $table->vector('embedding', 384)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
