<?php

namespace App\Services;

use App\Events\AgentMessageSent;
use App\Models\Conversation;
use App\Models\IndexedPage;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;
use League\CommonMark\CommonMarkConverter;

class OpenAiChatService
{
    const EMBEDDING_MODEL = 'text-embedding-3-small';

    /**
     * Main entry point to get a response from the assistant.
     * This version uses a safe, hardcoded fallback response.
     */
    public function getResponseAndBroadcast(Message $visitorMessage): void
    {
        try {
            $conversation = $visitorMessage->conversation;
            $userQuestion = $visitorMessage->body;
            $finalReply = null;

            // --- THE FINAL, STABLE LOGIC FLOW ---

            // Step 1: Attempt answer from the Assistant's internal knowledge (file_search).
            $this->infoLog("Step 1: Attempting answer from Assistant's file_search.");
            $primaryInstructions = $this->buildPrimaryPrompt();
            $assistantReply = $this->getAssistantResponse($conversation, $userQuestion, $primaryInstructions, true);

            if (!$this->isFallbackResponse($assistantReply)) {
                $this->infoLog("Step 1a: SUCCESS. Assistant found a direct answer from files.");
                $finalReply = $assistantReply;
            } else {
                // Step 2: Assistant didn't know. Try our indexed websites (RAG).
                $this->infoLog("Step 2: Fallback from files. Searching local DB (RAG).");
                $contextFromDb = $this->searchIndexedPagesSemantically($userQuestion);

                if ($contextFromDb) {
                    $this->infoLog("Step 2a: Found context in DB. Re-asking Assistant with RAG prompt.");
                    $ragInstructions = $this->buildRagPrompt($userQuestion, $contextFromDb);
                    $finalReply = $this->getAssistantResponse($conversation, $userQuestion, $ragInstructions, false);
                }
            }

            // Step 3: Final check. If we still have a fallback response, use the ultimate safe response.
            if ($finalReply === null || $this->isFallbackResponse($finalReply)) {
                $this->infoLog("Step 3: All sources failed. Using the hardcoded final fallback.");
                $finalReply = "شكرًا لسؤالك. حاليًا، لا تتوفر لدي معلومات دقيقة حول هذا الموضوع. للحصول على إجابة وافية، يرجى التواصل مع فريق الدعم لدينا عبر البريد الإلكتروني: anas@gmail.com";
            }

            // Step 4: Process and broadcast the final response.
            $this->processAndBroadcastReply($conversation, $finalReply);

        } catch (Throwable $e) {
            $this->handleServiceException($e, $visitorMessage);
        }
    }
    
    /**
     * Checks if the AI response is a generic "I don't know" type of answer.
     */
    private function isFallbackResponse(string $response): bool
    {
        $fallbacks = [
            "خارج نطاق خبرتي",
            "لا أستطيع توفير تفاصيل",
            "لا توجد معلومات",
            "لم أجد أي معلومات",
        ];

        foreach ($fallbacks as $fallback) {
            if (str_contains($response, $fallback)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Builds the primary prompt for the Assistant when using internal files.
     */
    private function buildPrimaryPrompt(): string
    {
        return <<<PROMPT
        **Your Persona:** You are "Andgrow's Expert Assistant". You are an internal expert with complete and direct knowledge of all company information. Your tone is confident, helpful, and professional. Respond in Arabic.
        **Core Directives:**
        1.  **Synthesize, Do Not Report:** Your primary function is to synthesize information from your knowledge base (the provided files) and present it as your own expertise.
        2.  **Absolute Prohibition:** Under no circumstances should you ever mention or allude to files, documents, your knowledge base, or the fact that you are searching for information. You are the source of the information.
        3.  **Answer Hierarchy:**
            *   **Priority 1 (Direct Answer):** If you find a direct answer in your knowledge base, provide it as a confident fact.
            *   **Priority 2 (Inference):** If the information doesn't exist but you can make a logical inference based on related content, present it as such. Start with "بناءً على مبادئنا، يمكننا استنتاج أن..." or similar phrasing.
            *   **Priority 3 (Fallback):** If the information is completely absent and no logical inference can be made, you MUST respond with the exact phrase: "هذا السؤال خارج نطاق خبرتي." Do not say anything else.
        PROMPT;
    }

    /**
     * Builds the prompt for RAG when using website context.
     */
    private function buildRagPrompt(string $userQuestion, string $context): string
    {
        return <<<PROMPT
        **Your Persona:** You are "Andgrow's Expert Assistant". Your tone is confident, helpful, and professional. Respond in Arabic.
        **Core Directive:** Your primary function is to synthesize information from the context provided below and present it as your own expertise. Answer the user's question based *only* on this context.
        **Absolute Prohibition:**
        - DO NOT mention files, documents, or "the context provided".
        - DO NOT use the source URLs or similarity scores in your response.
        - If the context does not contain the answer, you MUST respond with the exact phrase: "هذا السؤال خارج نطاق خبرتي."
        **--- CONTEXT FROM WEBSITE ---**
        {$context}
        **--- END OF CONTEXT ---**
        **User's Question:** "{$this->cleanUtf8($userQuestion)}"
        Based *only* on the context above, provide a direct answer to the user's question.
        PROMPT;
    }

    /**
     * The core logic for interacting with the OpenAI Assistant API.
     */
    private function getAssistantResponse(Conversation $conversation, string $userMessage, string $instructions, bool $useFileSearch): string
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

        Http::withHeaders($headers)->post("https://api.openai.com/v1/threads/{$threadId}/messages", ['role' => 'user', 'content' => $userMessage])->throw();
        $this->infoLog("Added message to thread {$threadId}");

        $tools = $useFileSearch ? [['type' => 'file_search']] : [];
        $payload = ['assistant_id' => $assistantId, 'instructions' => $instructions, 'tools' => $tools];
        $this->infoLog("Sending 'run' request to OpenAI.", $payload);

        $runResponse = Http::withHeaders($headers)->post("https://api.openai.com/v1/threads/{$threadId}/runs", $payload)->throw();
        $runId = $runResponse->json('id');
        $this->infoLog("Started assistant run {$runId} with file_search: " . ($useFileSearch ? 'enabled' : 'disabled'));
        
        $maxAttempts = 45; // Increased timeout for server
        $attempt = 0;
        $status = '';
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
        return "هذا السؤال خارج نطاق خبرتي.";
    }

    private function getEmbeddingFor(string $text): ?array
    {
        try {
            $response = OpenAI::embeddings()->create(['model' => self::EMBEDDING_MODEL, 'input' => $text]);
            return $response->embeddings[0]->embedding;
        } catch (Throwable $e) {
            Log::error('OpenAI Embedding API call failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    private function searchIndexedPagesSemantically(string $query, int $limit = 3): ?string
    {
        $queryEmbedding = $this->getEmbeddingFor($query);
        if (!$queryEmbedding) { return null; }

        $pages = IndexedPage::whereNotNull('embedding')->get();
        if ($pages->isEmpty()) { return null; }

        $pagesWithScores = $pages->map(function ($page) use ($queryEmbedding) {
            if (!is_array($page->embedding) || empty($page->embedding)) { return null; }
            return ['id' => $page->id, 'title' => $page->title, 'score' => $this->cosineSimilarity($queryEmbedding, $page->embedding)];
        })->filter()->sortByDesc('score');
        
        $topPages = $pagesWithScores->take($limit);
        
        $qualityThreshold = 0.6;
        
        $this->infoLog('Top 3 semantic search results (before filtering):', $topPages->toArray());

        if ($topPages->isEmpty() || $topPages->first()['score'] < $qualityThreshold) {
            $this->infoLog('No sufficiently similar results found.', ['best_score' => $topPages->first()['score'] ?? 0, 'threshold' => $qualityThreshold]);
            return null;
        }

        $topPageIds = $topPages->pluck('id');
        $finalPages = IndexedPage::findMany($topPageIds);
        $context = "Context from semantically similar pages:\n\n";
        foreach ($finalPages as $page) {
            $score = $topPages->firstWhere('id', $page->id)['score'];
            $context .= "--- Page: " . $page->title . " (Similarity Score: " . number_format($score, 2) . ")\n" . substr($this->cleanUtf8($page->content), 0, 4000) . "\n\n";
        }
        
        return $context;
    }

    private function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0.0; $normA = 0.0; $normB = 0.0;
        $count = count($vecA);
        if ($count === 0 || $count !== count($vecB)) { return 0.0; }
        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }
        $denominator = sqrt($normA) * sqrt($normB);
        return $denominator == 0 ? 0 : $dotProduct / $denominator;
    }

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

    private function handleServiceException(Throwable $e, ?Message $visitorMessage = null): void
    {
        Log::error("OpenAiChatService: EXCEPTION OCCURRED!", ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace_summary' => substr($e->getTraceAsString(), 0, 2000)]);
        if ($visitorMessage && $visitorMessage->conversation) {
            $errorMessage = $visitorMessage->conversation->messages()->create(['sender' => 'agent', 'body' => "I'm sorry, a technical error occurred. Please try again later."]);
            broadcast(new AgentMessageSent($errorMessage));
        }
    }

    private function cleanUtf8(string $text): string
    {
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    private function infoLog(string $message, array $context = []): void
    {
        Log::info("OpenAiChatService: " . $message, $context);
    }
}