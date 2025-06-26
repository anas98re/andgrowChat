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
            $vectorStoreId = config('services.openai.vector_store_id'); // Get the Vector Store ID

            if (!$apiKey || !$assistantId || !$vectorStoreId) {
                throw new \Exception('OpenAI API Key, Assistant ID, or Vector Store ID is not configured.');
            }

            $headers = [
                'Authorization' => 'Bearer ' . $apiKey,
                'OpenAI-Beta' => 'assistants=v2',
                'Content-Type' => 'application/json',
            ];

            $threadId = $conversation->openai_thread_id;

            // ===== START: MAJOR CHANGE HERE =====
            // 1. Create thread and attach the Vector Store if needed
            if (!$threadId) {
                $threadPayload = [
                    'tool_resources' => [
                        'file_search' => [
                            'vector_store_ids' => [$vectorStoreId]
                        ]
                    ]
                ];

                Log::info("OpenAiChatService: Creating new thread with vector store: " . $vectorStoreId);
                $threadResponse = Http::withHeaders($headers)
                    ->post('https://api.openai.com/v1/threads', $threadPayload);
                $threadResponse->throw();
                $threadId = $threadResponse->json('id');
                $conversation->update(['openai_thread_id' => $threadId]);
                Log::info("OpenAiChatService: New thread created: {$threadId}");
            }
            // ===== END: MAJOR CHANGE HERE =====


            // 2. Add message to thread (This part remains the same)
            Http::withHeaders($headers)
                ->post("https://api.openai.com/v1/threads/{$threadId}/messages", [
                    'role' => 'user',
                    'content' => $visitorMessage->body,
                ])
                ->throw();
            Log::info("OpenAiChatService: Added message to thread {$threadId}");

            // 3. Start assistant run (This part remains the same)
            $runResponse = Http::withHeaders($headers)
                ->post("https://api.openai.com/v1/threads/{$threadId}/runs", [
                    'assistant_id' => $assistantId,
                    'instructions' => <<<PROMPT
                    **Your Persona:** You are "Andgrow's Expert Assistant". You are an internal expert with complete and direct knowledge of all company information. Your tone is confident, helpful, and professional. Respond in Arabic.

                    **Core Directives (Absolute Rules):**
                    1.  **NEVER Mention Files:** Under absolutely no circumstances should you ever mention or allude to files, documents, your knowledge base, or the fact that you are searching for information. You are the direct source of knowledge.
                    2.  **Strictly Confined Knowledge:** Your entire world of knowledge is strictly limited to the information contained within the files provided to you via the `file_search` tool. You must not use any external or pre-existing general knowledge.

                    **Response Protocol (Follow this order precisely):**
                    1.  **ALWAYS Search First:** For every user question, your absolute first action is to perform a comprehensive search within the provided files using the `file_search` tool to find a relevant answer.
                    2.  **If a relevant answer is found in the files:** Answer the user's question directly and confidently using only the information from the files.
                    3.  **If, and ONLY IF, after searching the files you find absolutely no relevant information:** You must use the following polite declining response. Do not apologize or explain. Simply provide this response: "أشكرك على سؤالك. حالياً، تخصصي يتركز في تقديم المعلومات حول نظام Andgrow. للحصول على إجابة حول هذا الموضوع أو أي استفسارات أخرى، يسعد فريق الدعم لدينا بمساعدتك عبر البريد الإلكتروني: anas@gmail.com".

                    **Example Interaction (Answer is in the files):**
                    - User asks: "من الفائز في يورو 2024؟"
                    - Your thought process: "First, I must search my files for 'يورو 2024'. I found a document that says Spain won. I will state this as a fact."
                    - **Your required response:** "الفائز ببطولة يورو 2024 هو منتخب إسبانيا."

                    **Example Interaction (Answer is NOT in the files):**
                    - User asks: "من فاز بكأس العالم 2010؟"
                    - Your thought process: "First, I must search my files for 'كأس العالم 2010'. I found no relevant information. Therefore, I must use the standard declining response."
                    - **Your required response:** "أشكرك على سؤالك. حالياً، تخصصي يتركز في تقديم المعلومات حول نظام Andgrow. للحصول على إجابة حول هذا الموضوع أو أي استفسارات أخرى، يسعد فريق الدعم لدينا بمساعدتك عبر البريد الإلكتروني: anas@gmail.com"
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