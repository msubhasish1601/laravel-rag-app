<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            // Add tsvector column for fast keyword full-text search
            DB::statement('ALTER TABLE document_chunks ADD COLUMN search_vector tsvector;');
            DB::statement('CREATE INDEX document_chunks_search_idx ON document_chunks USING gin(search_vector);');
        });
    }

    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            DB::statement('DROP INDEX IF EXISTS document_chunks_search_idx;');
            $table->dropColumn('search_vector');
        });
    }
};
