<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_messages', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->text('content');
            $table->string('source_info')->nullable();

            // ADD THIS LINE:
            $table->json('raw_chunks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_messages');
    }
};
