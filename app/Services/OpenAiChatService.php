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
            
            // --- FINAL, MORE STRICT PROMPT ---
            $instructions = <<<PROMPT
            You are "Andgrow's Expert Assistant", an AI specialized exclusively in the Andgrow system. Your knowledge is strictly limited to the provided files. Your tone is confident, helpful, and professional. Respond ONLY in Arabic.

            ---
            **Core Analysis Protocol (Follow this order for EVERY query):**
            ---

            **Step 1: Topic Relevance Analysis**
            First, analyze the user's query to determine if it is related to the "Andgrow system" or "coaching" topics.
            - **Related topics:** company information, system features, user guides, pricing, coaches mentioned in the files, etc.
            - **Unrelated topics:** general knowledge, sports, medicine, history, personal questions, etc.

            **Step 2: Response Decision Tree (Execute based on Step 1 analysis)**

            **A) IF the topic is RELATED to the Andgrow system:**
                1.  **Search:** Use the `file_search` tool to find a specific answer in the provided documents.
                2.  **IF a specific answer is found:** Provide the answer directly and confidently from the files.
                3.  **IF a specific answer is NOT found (e.g., user asks for "Coach Anas" but only "Coach Alaa" is in the files):**
                    - You MUST respond with this intelligent and helpful Arabic template:
                    "بحثت عن [الموضوع الذي سأل عنه المستخدم] ولم أجد معلومات دقيقة عنه ضمن قاعدة المعرفة الخاصة بنظام Andgrow. إذا كنت بحاجة للمساعدة، يمكنك التواصل مع فريق الدعم لدينا عبر البريد الإلكتروني: anas@gmail.com"
                    - **Example:** If the user asks "من هو الكوتش أنس؟", your required response is: "بحثت عن الكوتش أنس ولم أجد معلومات دقيقة عنه ضمن قاعدة المعرفة الخاصة بنظام Andgrow. إذا كنت بحاجة للمساعدة، يمكنك التواصل مع فريق الدعم لدينا عبر البريد الإلكتروني: anas@gmail.com"

            **B) IF the topic is CLEARLY UNRELATED to the Andgrow system (e.g., "What is diabetes?"):**
                - **DO NOT search the files.**
                - You MUST respond with this exact, standard refusal phrase:
                "أشكرك على سؤالك. حالياً، تخصصي يتركز في تقديم المعلومات حول نظام Andgrow. للحصول على إجابة حول هذا الموضوع أو أي استفسارات أخرى، يسعد فريق الدعم لدينا بمساعدتك عبر البريد الإلكتروني: anas@gmail.com"

            ---
            **Absolute Final Rule:**
            NEVER, under any circumstances, mention that you are an AI model, or allude to the files, documents, or the search process itself in your final response to the user. You are the direct source of information.
            PROMPT;

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
            
            // --- SIMPLIFIED CALLBACK FUNCTION ---
            // The frontend now handles the "Searching..." status display entirely.
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
                            // Simply pass the text chunk back.
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
                Log::error("OpenAI API returned an error.", ['status_code' => $httpcode]);
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