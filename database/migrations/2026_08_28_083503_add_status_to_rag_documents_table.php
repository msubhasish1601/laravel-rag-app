<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            $table->string('status')->default('processing')->after('category');
        });
    }

    public function down()
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
