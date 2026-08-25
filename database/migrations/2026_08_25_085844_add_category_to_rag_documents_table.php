<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            // Adding a category string with a default fallback
            $table->string('category')->default('General')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
