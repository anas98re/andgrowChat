<?php
// In ..._add_embedding_to_indexed_pages_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indexed_pages', function (Blueprint $table) {
            // We use JSON to store the array of floating-point numbers (the vector).
            // It's flexible and supported by Laravel's casting.
            // Using ->after('content') is just for organization.
            $table->json('embedding')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('indexed_pages', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });
    }
};