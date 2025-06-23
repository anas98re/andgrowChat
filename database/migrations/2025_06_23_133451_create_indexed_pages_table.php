<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
    {
        Schema::create('indexed_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trusted_site_id')->constrained('trusted_sites')->onDelete('cascade');
            $table->string('url', 1024)->unique(); // Use a generous length for URLs and make them unique
            $table->string('title', 512)->nullable();
            $table->mediumText('content'); // Cleaned text content
            $table->timestamp('last_crawled_at');
            $table->timestamps();

            // For Full-Text Search later on MySQL
            $table->fullText(['title', 'content']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indexed_pages');
    }
};
