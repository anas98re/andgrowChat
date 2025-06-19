<?php

namespace App\Jobs;

use App\Events\AgentMessageSent;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log; 
use Throwable;

class AskCustomGpt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Message $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->onQueue('chat_ai');
    }

    public function handle(): void
    {
        try {
            Log::info('Starting AskCustomGpt job', [
                'message_id' => $this->message->id,
                'broadcast_driver' => config('broadcasting.default'),
                'pusher_app_id' => config('broadcasting.connections.pusher.app_id')
            ]);

            $conversation = $this->message->conversation;
            if (!$conversation) {
                Log::error("Conversation not found for message ID: " . $this->message->id);
                return;
            }

            $apiKey = config('openai.api_key'); 
            $assistantId = config('services.openai.assistant_id'); 

            if (!$apiKey || !$assistantId) {
                Log::error('OpenAI Configuration Missing', [
                    'has_api_key' => !empty($apiKey),
                    'has_assistant_id' => !empty($assistantId)
                ]);
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
                Log::info("Creating new thread for conversation {$conversation->id}");
                $threadResponse = Http::withHeaders($headers)
                    ->post('https://api.openai.com/v1/threads');

                if (!$threadResponse->successful()) {
                    Log::error('OpenAI thread create failed', ['response' => $threadResponse->body()]);
                    throw new \Exception('Failed to create thread. Response: ' . $threadResponse->body());
                }

                $thread = $threadResponse->json();
                $threadId = $thread['id'] ?? null;

                if (!$threadId) {
                    throw new \Exception('Thread creation failed, no id in response');
                }
                
                $conversation->update(['openai_thread_id' => $threadId]);
                Log::info("New thread created: {$threadId}");
            }

            // 2. Add message to thread
            Log::info("Adding message to thread {$threadId}");
            Http::withHeaders($headers)
                ->post("https://api.openai.com/v1/threads/{$threadId}/messages", [
                    'role' => 'user',
                    'content' => $this->message->body,
                ])
                ->throw(); 

            // 3. Start assistant run
            Log::info("Starting assistant run for thread {$threadId}");
            $runResponse = Http::withHeaders($headers)
                ->post("https://api.openai.com/v1/threads/{$threadId}/runs", [
                    'assistant_id' => $assistantId,
                    'instructions' => "Please address the user in Arabic. Prioritize information from the attached files.",
                    'tools' => [
                        ['type' => 'file_search']
                    ]
                ])
                ->throw();

            $runId = $runResponse->json('id');
            
            // 4. Poll for completion
            $maxAttempts = 20;
            $attempt = 0;
            $runStatus = null;

            do {
                sleep(2);
                
                $runStatusResponse = Http::withHeaders($headers)
                    ->get("https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}")
                    ->throw();

                $runStatus = $runStatusResponse->json();
                $status = $runStatus['status'] ?? 'unknown';
                Log::info("Run status: {$status} (Attempt: {$attempt})");
                $attempt++;

            } while (
                in_array($status, ['queued', 'in_progress']) &&
                $attempt < $maxAttempts
            );

            if ($status !== 'completed') {
                Log::error("Run failed with status: {$status}", ['run' => $runStatus]);
                throw new \Exception("Assistant run failed with status: {$status}");
            }

            // 5. Get assistant response
            Log::info("Fetching assistant response from thread {$threadId}");
            $messagesResponse = Http::withHeaders($headers)
                ->get("https://api.openai.com/v1/threads/{$threadId}/messages", ['limit' => 10]);

            $messages = $messagesResponse->json('data') ?? [];

            $agentReply = "Sorry, I couldn't find a response from the Assistant.";
            foreach ($messages as $msg) {
                if ($msg['role'] === 'assistant') {
                    $agentReply = $msg['content'][0]['text']['value'] ?? 'Assistant sent an empty message.';
                    break;
                }
            }

            // 6. Save message
            $agentMessage = $conversation->messages()->create([
                'sender' => 'agent',
                'body' => $agentReply,
            ]);

            Log::info('Agent message saved', [
                'message_id' => $agentMessage->id,
                'conversation_id' => $conversation->id,
                'session_id' => $conversation->session_id
            ]);

            // 7. CRITICAL: Broadcasting with debug
            Log::info('About to broadcast AgentMessageSent event', [
                'message_id' => $agentMessage->id,
                'session_id' => $conversation->session_id,
                'channel' => 'chat-session.' . $conversation->session_id,
                'broadcast_driver' => config('broadcasting.default'),
                'pusher_configured' => config('broadcasting.connections.pusher.app_id') ? 'yes' : 'no'
            ]);

            // broadcast(new AgentMessageSent($agentMessage))->toOthers();
            broadcast(new AgentMessageSent($agentMessage));
            
            Log::info('AgentMessageSent event broadcasted successfully');

        } catch (Throwable $e) {
            Log::error("OpenAI Chat Job failed: " . $e->getMessage(), [
                'message_id' => $this->message->id,
                'conversation_id' => $this->message->conversation->id ?? 'N/A',
                'error_trace' => $e->getTraceAsString(),
            ]);

            $errorMessage = $this->message->conversation->messages()->create([
                'sender' => 'agent',
                'body' => "I'm sorry, an error occurred while processing your request. Please try again later.",
            ]);
            
            Log::info('Broadcasting error message');
            broadcast(new AgentMessageSent($errorMessage))->toOthers();
        }
    }
}