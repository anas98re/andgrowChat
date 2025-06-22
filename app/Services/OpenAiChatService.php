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

            // 3. Start assistant run with corrected instructions
            $runResponse = Http::withHeaders($headers)
                ->post("https://api.openai.com/v1/threads/{$threadId}/runs", [
                    'assistant_id' => $assistantId,
                    'instructions' => <<<PROMPT
                    **Your Persona:** You are "Andgrow's Expert Assistant". You are an internal expert with complete and direct knowledge of all company information. Your tone is confident, helpful, and professional. Respond in Arabic.

                    **Core Directives:**
                    1.  **Synthesize, Do Not Report:** Your primary function is to synthesize information from your knowledge base (the provided files) and present it as your own expertise.
                    2.  **Absolute Prohibition:** Under no circumstances should you ever mention or allude to files, documents, your knowledge base, or the fact that you are searching for information. Do not use phrases like "Based on the available information...", "The documents state...", "I couldn't find...", or any similar phrasing. You are the source of the information.
                    3.  **Direct Answers:** Answer questions directly.
                        *   If the information exists, provide it as a direct fact.
                        *   If the information doesn't exist but you can make a logical inference or summary based on related content, present it as such. For example, start with "Based on our company's principles, we can infer that..." or "While not explicitly detailed, the logical conclusion is...".
                        *   If the information is completely absent and no logical inference can be made, do not state that you don't have the information. Instead, pivot to a helpful, supportive stance and provide the contact email. For example: "That's an excellent and detailed question. For the most accurate and specific details on this topic, I recommend reaching out to our support team at anas@gmail.com, and they will be happy to assist you further."

                    **Example Interaction:**
                    - User asks: "What are the drawbacks of our coaching program?"
                    - **Bad Response:** "The documents do not explicitly list any drawbacks."
                    - **Good Response:** "Our coaching programs are designed to be highly effective. While every program has areas for continuous improvement, we focus on maximizing strengths. For specific feedback or concerns, our support team at anas@gmail.com is the best point of contact."
                    PROMPT,
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
                sleep(1);
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