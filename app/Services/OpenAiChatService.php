<?php

namespace App\Services;

use App\Events\AgentMessageSent;
use App\Models\Conversation;
use App\Models\IndexedPage;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI; // Use the official Laravel Facade
use Throwable;
use League\CommonMark\CommonMarkConverter;

class OpenAiChatService
{
    const EMBEDDING_MODEL = 'text-embedding-3-small';

    /**
     * Main entry point to get a response. It uses semantic search first,
     * then falls back to the Assistant's internal knowledge.
     */
    public function getResponseAndBroadcast(Message $visitorMessage): void
    {
        try {
            $conversation = $visitorMessage->conversation;
            if (!$conversation) {
                Log::error("OpenAiChatService: Conversation not found for message ID: " . $visitorMessage->id);
                return;
            }
            $userQuestion = $visitorMessage->body;

            $this->infoLog("Searching with SEMANTIC search for: '{$userQuestion}'");
            $contextFromDb = $this->searchIndexedPagesSemantically($userQuestion);

            if ($contextFromDb) {
                $this->infoLog("Found relevant context via SEMANTIC search. Using RAG.");
                $instructions = $this->buildRagPrompt($userQuestion, $contextFromDb);
            } else {
                $this->infoLog("No context found via semantic search. Falling back to file_search.");
                $instructions = $this->buildDefaultPrompt();
            }

            $agentReplyMarkdown = $this->getAssistantResponse($conversation, $userQuestion, $instructions);
            $this->processAndBroadcastReply($conversation, $agentReplyMarkdown);

        } catch (Throwable $e) {
            $this->handleServiceException($e, $visitorMessage);
        }
    }

    /**
     * Get the vector embedding for a given text from OpenAI.
     */
    private function getEmbeddingFor(string $text): ?array
    {
        try {
            // This uses the official openai-php/laravel package facade
            $response = OpenAI::embeddings()->create([
                'model' => self::EMBEDDING_MODEL,
                'input' => $text,
            ]);
            return $response->embeddings[0]->embedding;
        } catch (Throwable $e) {
            Log::error('OpenAI Embedding API call failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Performs a semantic search using vector embeddings. This is the "smart" search.
     */
        /**
     * Performs a semantic search using vector embeddings.
     * (UPDATED WITH MORE DETAILED LOGGING FOR DEBUGGING)
     */
    private function searchIndexedPagesSemantically(string $query, int $limit = 3): ?string
    {
        // 1. Get embedding for the user's query
        $queryEmbedding = $this->getEmbeddingFor($query);
        if (!$queryEmbedding) {
            $this->infoLog('Could not generate embedding for the query, aborting semantic search.');
            return null;
        }

        // 2. Fetch pages
        $pages = IndexedPage::whereNotNull('embedding')->get();
        if ($pages->isEmpty()) {
            $this->infoLog('No indexed pages with embeddings found.');
            return null;
        }

        // 3. Calculate similarity
        $pagesWithScores = $pages->map(function ($page) use ($queryEmbedding) {
            return [
                'id' => $page->id,
                'title' => $page->title,
                'score' => $this->cosineSimilarity($queryEmbedding, $page->embedding)
            ];
        });

        // 4. Sort and take top results
        $topPages = $pagesWithScores->sortByDesc('score')->take($limit);

        // --- DEBUG LOGGING ---
        // Let's log the top 3 results BEFORE filtering to see the actual scores.
        $this->infoLog('Top 3 semantic search results (before filtering):', $topPages->toArray());
        // --- END DEBUG LOGGING ---

        // 5. Filter out low-quality matches
        $qualityThreshold = 0.7; // The quality filter value
        if ($topPages->isEmpty() || $topPages->first()['score'] < $qualityThreshold) {
            $this->infoLog('No sufficiently similar results found. Best score was below threshold of ' . $qualityThreshold, [
                'best_score' => number_format($topPages->first()['score'] ?? 0, 4)
            ]);
            return null;
        }

        // 6. Build context from the actual Page models
        $topPageIds = $topPages->pluck('id');
        $finalPages = IndexedPage::findMany($topPageIds);

        $context = "Context from semantically similar pages:\n\n";
        foreach ($finalPages as $page) {
            // Find the score for the current page to include in context
            $score = $topPages->firstWhere('id', $page->id)['score'];
            $context .= "--- Page: " . $page->title . " (Similarity Score: " . number_format($score, 2) . ")\n";
            $context .= substr($page->content, 0, 4000) . "\n\n";
        }
        
        return $context;
    }

    /**
     * Calculates the cosine similarity between two vectors (arrays of floats).
     */
    private function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $count = count($vecA);

        if ($count === 0 || $count !== count($vecB)) {
            return 0.0;
        }

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }
        
        $denominator = sqrt($normA) * sqrt($normB);
        return $denominator == 0 ? 0 : $dotProduct / $denominator;
    }

    /**
     * Builds the prompt for Retrieval-Augmented Generation (RAG).
     */
    private function buildRagPrompt(string $userQuestion, string $context): string
    {
        return <<<PROMPT
        **Your Persona:** You are "Andgrow's Expert Assistant". Your tone is confident, helpful, and professional. Respond in Arabic.
        **Core Directive:** Your primary function is to synthesize information from the context provided below and present it as your own expertise. Answer the user's question based *only* on this context.
        **Absolute Prohibition:**
        - DO NOT mention files, documents, or "the context provided".
        - DO NOT use the source URLs or similarity scores in your response.
        - If the context does not contain the answer, state that you cannot provide specific details on that topic and recommend contacting support at anas@gmail.com. Do not try to answer from your general knowledge.
        **--- CONTEXT FROM WEBSITE ---**
        {$context}
        **--- END OF CONTEXT ---**
        **User's Question:** "{$userQuestion}"
        Based *only* on the context above, provide a direct answer to the user's question.
        PROMPT;
    }

    /**
     * Builds the strict default prompt to prevent out-of-scope answers.
     */
    private function buildDefaultPrompt(): string
    {
        return <<<PROMPT
        **Your Persona:** You are "Andgrow's Expert Assistant".
        **CRITICAL RULE:** Your knowledge is STRICTLY LIMITED to the information contained in the files provided to you. You MUST NOT use any external or general knowledge. Your function is to be an expert on the provided files only.
        **Core Directives:**
        1.  Answer questions by synthesizing information from your knowledge base (the provided files).
        2.  If a question is about a topic NOT covered in your files (e.g., general knowledge, history, science, other companies), you MUST respond with this exact phrase in Arabic: "هذا السؤال خارج نطاق خبرتي."
        3.  Under no circumstances should you ever mention the files, your knowledge base, or searching.
        **Example Interaction:**
        - User asks: "What is the capital of France?"
        - **Your ONLY valid response:** "هذا السؤال خارج نطاق خبرتي."
        - User asks: "متى بدأت الحرب العالمية الثانية؟"
        - **Your ONLY valid response:** "هذا السؤال خارج نطاق خبرتي."
        PROMPT;
    }

    /**
     * The core logic for interacting with the OpenAI Assistant API.
     */
    private function getAssistantResponse(Conversation $conversation, string $userMessage, string $instructions): string
    {
        $apiKey = config('openai.api_key');
        $assistantId = config('services.openai.assistant_id');
        $threadId = $conversation->openai_thread_id;
        $headers = ['Authorization' => 'Bearer ' . $apiKey, 'OpenAI-Beta' => 'assistants=v2', 'Content-Type' => 'application/json'];

        if (!$threadId) {
            $threadResponse = Http::withHeaders($headers)->post('https://api.openai.com/v1/threads');
            $threadResponse->throw();
            $threadId = $threadResponse->json('id');
            $conversation->update(['openai_thread_id' => $threadId]);
            $this->infoLog("New thread created: {$threadId}");
        }

        Http::withHeaders($headers)
            ->post("https://api.openai.com/v1/threads/{$threadId}/messages", ['role' => 'user', 'content' => $userMessage])
            ->throw();
        $this->infoLog("Added message to thread {$threadId}");

        $tools = str_contains($instructions, 'CONTEXT FROM WEBSITE') ? [] : [['type' => 'file_search']];

        $runResponse = Http::withHeaders($headers)
            ->post("https://api.openai.com/v1/threads/{$threadId}/runs", ['assistant_id' => $assistantId, 'instructions' => $instructions, 'tools' => $tools])
            ->throw();
        $runId = $runResponse->json('id');
        $this->infoLog("Started assistant run {$runId} with " . (empty($tools) ? "RAG" : "file_search") . " mode.");
        
        $maxAttempts = 20; $attempt = 0; $status = '';
        do {
            sleep(1);
            $runStatusResponse = Http::withHeaders($headers)->get("https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}")->throw();
            $status = $runStatusResponse->json('status');
            $this->infoLog("Run status is '{$status}' (Attempt: {$attempt})");
            $attempt++;
        } while (in_array($status, ['queued', 'in_progress']) && $attempt < $maxAttempts);

        if ($status !== 'completed') {
            throw new \Exception("Assistant run did not complete. Final status: {$status}");
        }
        
        $messagesResponse = Http::withHeaders($headers)->get("https://api.openai.com/v1/threads/{$threadId}/messages", ['limit' => 10])->throw();
        $messagesData = $messagesResponse->json('data', []);

        foreach ($messagesData as $msg) {
            if ($msg['role'] === 'assistant') {
                return $msg['content'][0]['text']['value'] ?? 'Assistant sent an empty message.';
            }
        }
        return "Sorry, I couldn't find a response.";
    }

    /**
     * Cleans, saves, and broadcasts the final reply.
     */
    private function processAndBroadcastReply(Conversation $conversation, string $agentReplyMarkdown): void
    {
        $pattern = '/【.*?】/u';
        $cleanedMarkdown = preg_replace($pattern, '', $agentReplyMarkdown);
        $cleanedMarkdown = trim($cleanedMarkdown);
        $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $agentReplyHtml = $converter->convert($cleanedMarkdown)->getContent();
        $agentMessage = $conversation->messages()->create(['sender' => 'agent', 'body' => $agentReplyHtml]);
        $this->infoLog('Agent message saved as HTML', ['id' => $agentMessage->id]);
        broadcast(new AgentMessageSent($agentMessage));
        $this->infoLog('AgentMessageSent event broadcasted successfully.');
    }

    /**
     * Centralized exception handler.
     */
    private function handleServiceException(Throwable $e, ?Message $visitorMessage = null): void
    {
        Log::error("OpenAiChatService: EXCEPTION OCCURRED!", ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace_summary' => substr($e->getTraceAsString(), 0, 2000)]);
        if ($visitorMessage && $visitorMessage->conversation) {
            $errorMessage = $visitorMessage->conversation->messages()->create(['sender' => 'agent', 'body' => "I'm sorry, a technical error occurred. Please try again later."]);
            broadcast(new AgentMessageSent($errorMessage));
        }
    }

    /**
     * A simple helper for consistent logging.
     */
    private function infoLog(string $message, array $context = []): void
    {
        Log::info("OpenAiChatService: " . $message, $context);
    }
}