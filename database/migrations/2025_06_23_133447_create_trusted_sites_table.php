<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
     public function up(): void
    {
        Schema::create('trusted_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Company Blog"
            $table->string('url');  // e.g., "https://mycompany.com/blog"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_sites');
    }
};
