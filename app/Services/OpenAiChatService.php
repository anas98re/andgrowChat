<?php

namespace App\Services;

use App\Events\AgentMessageSent;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use League\CommonMark\CommonMarkConverter;

class OpenAiChatService
{
    /**
     * Process a visitor's message, get a response from OpenAI,
     * save it, and broadcast it in real-time.
     *
     * @param Message $visitorMessage The message sent by the visitor.
     * @return void
     */
    public function getResponseAndBroadcast(Message $visitorMessage): void
    {
        try {
            $conversation = $visitorMessage->conversation;
            if (!$conversation) {
                Log::error("OpenAiChatService: Conversation not found for message ID: " . $visitorMessage->id);
                return;
            }

            Log::info("OpenAiChatService: Starting for conversation_id: " . $conversation->id);

            $apiKey = config('openai.api_key');
            $assistantId = config('services.openai.assistant_id');

            if (!$apiKey || !$assistantId) {
                throw new \Exception('OpenAI API Key or Assistant ID is not configured.');
            }

            $headers = [
                'Authorization' => 'Bearer ' . $apiKey,
                'OpenAI-Beta' => 'assistants=v2',
                'Content-Type' => 'application/json',
            ];

            $threadId = $conversation->openai_thread_id;

            // 1. Create thread if needed
            if (!$threadId) {
                $threadResponse = Http::withHeaders($headers)->post('https://api.openai.com/v1/threads');
                $threadResponse->throw();
                $threadId = $threadResponse->json('id');
                $conversation->update(['openai_thread_id' => $threadId]);
                Log::info("OpenAiChatService: New thread created: {$threadId}");
            }

            // 2. Add message to thread
            Http::withHeaders($headers)
                ->post("https://api.openai.com/v1/threads/{$threadId}/messages", [
                    'role' => 'user',
                    'content' => $visitorMessage->body,
                ])
                ->throw();
            Log::info("OpenAiChatService: Added message to thread {$threadId}");

            // 3. Start assistant run
            $runResponse = Http::withHeaders($headers)
                ->post("https://api.openai.com/v1/threads/{$threadId}/runs", [
                    'assistant_id' => $assistantId,
                    'instructions' => "Please address the user in Arabic. Prioritize information from the attached files.",
                    'tools' => [['type' => 'file_search']]
                ])
                ->throw();
            $runId = $runResponse->json('id');
            Log::info("OpenAiChatService: Started assistant run {$runId}");

            // 4. Poll for completion
            $maxAttempts = 20;
            $attempt = 0;
            $status = '';
            do {
                sleep(1); // <<-- I changed it to 1 to reduce the delay.
                $runStatusResponse = Http::withHeaders($headers)->get("https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}")->throw();
                $status = $runStatusResponse->json('status');
                Log::info("OpenAiChatService: Run status is '{$status}' (Attempt: {$attempt})");
                $attempt++;
            } while (in_array($status, ['queued', 'in_progress']) && $attempt < $maxAttempts);

            if ($status !== 'completed') {
                throw new \Exception("Assistant run did not complete. Final status: {$status}");
            }

            // 5. Get assistant response
            $messagesResponse = Http::withHeaders($headers)->get("https://api.openai.com/v1/threads/{$threadId}/messages", ['limit' => 10])->throw();
            $messagesData = $messagesResponse->json('data', []); 

            $agentReplyMarkdown = "Sorry, I couldn't find a response.";
            foreach ($messagesData as $msg) { 
                if ($msg['role'] === 'assistant') {
                    $agentReplyMarkdown = $msg['content'][0]['text']['value'] ?? 'Assistant sent an empty message.';
                    break;
                }
            }
            Log::info("OpenAiChatService: Fetched assistant response as Markdown.");

            // 6. Clean up the response and convert to HTML
            $pattern = '/【.*?】/u';
            $cleanedMarkdown = preg_replace($pattern, '', $agentReplyMarkdown);
            $cleanedMarkdown = trim($cleanedMarkdown);
            
            $converter = new CommonMarkConverter([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);

            $agentReplyHtml = $converter->convert($cleanedMarkdown)->getContent();

            // 7. Save and broadcast the HTML formatted message
            $agentMessage = $conversation->messages()->create([
                'sender' => 'agent',
                'body' => $agentReplyHtml,
            ]);
            Log::info('OpenAiChatService: Agent message saved as HTML', ['id' => $agentMessage->id]);

            broadcast(new AgentMessageSent($agentMessage));
            Log::info('OpenAiChatService: AgentMessageSent event broadcasted successfully.');

        } catch (Throwable $e) {
            Log::error("OpenAiChatService: EXCEPTION OCCURRED!", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            if (isset($visitorMessage)) {
                $errorMessage = $visitorMessage->conversation->messages()->create([
                    'sender' => 'agent',
                    'body' => "I'm sorry, a technical error occurred. Please try again later.",
                ]);
                broadcast(new AgentMessageSent($errorMessage));
            }
        }
    }
}