<?php
// In app/Console/Commands/GenerateEmbeddings.php

namespace App\Console\Commands;

use App\Models\IndexedPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateEmbeddings extends Command
{
    protected $signature = 'sites:embed {--fresh : Regenerate embeddings for all pages}';
    protected $description = 'Generates vector embeddings for indexed pages using OpenAI.';

    // The recommended model for performance and cost.
    const EMBEDDING_MODEL = 'text-embedding-3-small';

    public function handle(): int
    {
        $this->info("Starting to generate embeddings...");

        $apiKey = config('openai.api_key');
        if (!$apiKey) {
            $this->error("OpenAI API key is not configured. Please check your .env file.");
            return 1;
        }

        // Determine which pages to process
        $query = IndexedPage::query();
        if (!$this->option('fresh')) {
            // By default, only process pages that don't have an embedding yet.
            $query->whereNull('embedding');
        }

        $totalPages = $query->count();
        if ($totalPages === 0) {
            $this->info("No pages to process. All indexed pages already have embeddings.");
            return 0;
        }
        $this->info("Found {$totalPages} pages to process.");
        
        $progressBar = $this->output->createProgressBar($totalPages);
        $progressBar->start();
        
        // Process pages in chunks to be memory-efficient
        $query->chunk(50, function ($pages) use ($apiKey, $progressBar) {
            foreach ($pages as $page) {
                try {
                    $response = Http::withToken($apiKey)
                        ->timeout(30) // Set a reasonable timeout
                        ->post('https://api.openai.com/v1/embeddings', [
                            'model' => self::EMBEDDING_MODEL,
                            'input' => substr($page->content, 0, 8000) // Use a substring to stay within token limits
                        ]);
                    
                    if ($response->failed()) {
                        $this->error("\nAPI Error for page ID {$page->id}: " . $response->body());
                        continue; // Skip to the next page
                    }
                    
                    $embedding = $response->json('data.0.embedding');

                    if ($embedding) {
                        $page->update(['embedding' => $embedding]);
                    }

                } catch (Throwable $e) {
                    $this->error("\nException for page ID {$page->id}: " . $e->getMessage());
                    Log::error("Embedding generation failed for page {$page->id}", ['error' => $e->getMessage()]);
                }
                
                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->info("\n\nEmbedding generation complete!");
        return 0;
    }
}