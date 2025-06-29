<?php

// File: app/Services/OpenAiChatService.php

namespace App\Services;

use App\Events\AgentMessageSent;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use League\CommonMark\CommonMarkConverter;

class OpenAiChatService
{
    // The function signature is changed to accept a new boolean parameter.
    public function streamResponse(Message $visitorMessage, callable $streamCallback): void
    {
        $conversation = $visitorMessage->conversation;
        $fullResponseText = '';

        try {
            Log::info("OpenAiChatService (Stream): Starting for conversation_id: " . $conversation->id);
            $apiKey = config('openai.api_key');
            $assistantId = config('services.openai.assistant_id');
            $vectorStoreId = config('services.openai.vector_store_id');

            if (!$apiKey || !$assistantId || !$vectorStoreId) {
                throw new \Exception('OpenAI API Key, Assistant ID, or Vector Store ID is not configured.');
            }
            
            $headers = [
                'Authorization' => 'Bearer ' . $apiKey,
                'OpenAI-Beta' => 'assistants=v2',
                'Content-Type' => 'application/json',
            ];
            
            $threadId = $conversation->openai_thread_id;
            if (!$threadId) {
                $threadResponse = Http::withHeaders($headers)->post('https://api.openai.com/v1/threads', [
                    'tool_resources' => ['file_search' => ['vector_store_ids' => [$vectorStoreId]]]
                ]);
                $threadResponse->throw();
                $threadId = $threadResponse->json('id');
                $conversation->update(['openai_thread_id' => $threadId]);
            }

            Http::withHeaders($headers)->post("https://api.openai.com/v1/threads/{$threadId}/messages", [
                'role' => 'user', 'content' => $visitorMessage->body,
            ])->throw();

            $ch = curl_init();
            
            $curlHeaders = [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'OpenAI-Beta: assistants=v2',
            ];
            
            // The latest, most robust prompt
            $instructions = config('chatbot.assistant_prompt');

            $payload = json_encode([
                'assistant_id' => $assistantId,
                'instructions' => $instructions,
                'tools' => [['type' => 'file_search']],
                'stream' => true,
            ]);

            curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/threads/{$threadId}/runs");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            
            // This function is now simplified and no longer sends status events.
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use ($streamCallback, &$fullResponseText) {
                $lines = explode("\n", trim($data));
                foreach ($lines as $line) {
                    if (strpos($line, 'data: ') === 0) {
                        $jsonStr = substr($line, 6);
                        if ($jsonStr === '[DONE]') continue;
                        
                        $eventData = json_decode($jsonStr, true);
                        if (isset($eventData['delta']['content'][0]['text']['value'])) {
                            $textChunk = $eventData['delta']['content'][0]['text']['value'];
                            $fullResponseText .= $textChunk;
                            $streamCallback(['type' => 'text', 'data' => $textChunk]);
                        }
                    }
                }
                return strlen($data);
            });

            curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpcode >= 400) {
                throw new \Exception("OpenAI API Error: " . $httpcode);
            }

            if (!empty(trim($fullResponseText))) {
                $this->saveAndBroadcastFinalMessage($conversation, $fullResponseText);
            }

        } catch (Throwable $e) {
            Log::error("OpenAiChatService (Stream): EXCEPTION!", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function saveAndBroadcastFinalMessage(Conversation $conversation, string $markdownText): void
    {
        $pattern = '/【.*?】/u';
        $cleanedMarkdown = preg_replace($pattern, '', $markdownText);
        $cleanedMarkdown = trim($cleanedMarkdown);
        
        $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $agentReplyHtml = $converter->convert($cleanedMarkdown)->getContent();

        $agentMessage = $conversation->messages()->create([
            'sender' => 'agent',
            'body' => $agentReplyHtml,
        ]);

        broadcast(new AgentMessageSent($agentMessage));
    }
}