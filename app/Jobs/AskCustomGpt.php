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
use Throwable; // Import Throwable to handle all types of errors

class AskCustomGpt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Message $message;

    /**
     * Create a new job instance.
     */
    public function __construct(Message $message)
    {
        $this->message = $message;
        // Specify the queue name to ensure that the correct worker handles it.
         $this->onQueue('chat_ai');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $conversation = $this->message->conversation;
            if (!$conversation) {
                Log::error("Conversation not found for message ID: " . $this->message->id);
                return;
            }

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

            // 1. Create a new Thread only if it does not already exist
            if (!$threadId) {
                Log::info("No thread ID found for conversation {$conversation->id}. Creating a new one.");
                $threadResponse = Http::withHeaders($headers)
                    ->post('https://api.openai.com/v1/threads');

                if (!$threadResponse->successful()) {
                    Log::error('OpenAI thread create failed: ', ['response' => $threadResponse->body()]);
                    throw new \Exception('Failed to create thread. Response: ' . $threadResponse->body());
                }

                $thread = $threadResponse->json();
                $threadId = $thread['id'] ?? null;

                if (!$threadId) {
                    throw new \Exception('Thread creation failed, no id in response');
                }
                
                $conversation->update(['openai_thread_id' => $threadId]);
                Log::info("New thread created and saved: {$threadId}");
            }

           // 2. Add the user's message to the thread
            Log::info("Adding message to thread {$threadId}");
            Http::withHeaders($headers)
                ->post("https://api.openai.com/v1/threads/{$threadId}/messages", [
                    'role' => 'user',
                    'content' => $this->message->body,
                ])
                ->throw(); 

            // 3- Start the Assistant
            // Log::info("Starting a run for assistant {$assistantId} on thread {$threadId}");
            // $runResponse = Http::withHeaders($headers)
            //     ->post("https://api.openai.com/v1/threads/{$threadId}/runs", [
            //         'assistant_id' => $assistantId,
            //     ])
            //     ->throw();
            // 3- Start the Assistant
            Log::info("Starting a run for assistant {$assistantId} on thread {$threadId}");
            $runResponse = Http::withHeaders($headers)
                ->post("https://api.openai.com/v1/threads/{$threadId}/runs", [
                    'assistant_id' => $assistantId,
                    'instructions' => "Please address the user in Arabic. Prioritize information from the attached files.", // يمكنك إضافة تعليمات إضافية هنا
                    'tools' => [ // <-- This is the most important part we added. (for file searching)
                        ['type' => 'file_search']
                    ]
                ])
                ->throw();

            $runId = $runResponse->json('id');
            
            // 4. Wait until the process is complete (Polling)
            $maxAttempts = 20; // Increase the number of attempts to 40 seconds
            $attempt = 0;
            $runStatus = null;

            do {
                sleep(2); //Wait two seconds between each attempt.
                
                $runStatusResponse = Http::withHeaders($headers)
                    ->get("https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}")
                    ->throw();

                $runStatus = $runStatusResponse->json();
                $status = $runStatus['status'] ?? 'unknown';
                Log::info("Run ID {$runId} status: {$status} (Attempt: {$attempt})");
                $attempt++;

            } while (
                in_array($status, ['queued', 'in_progress']) &&
                $attempt < $maxAttempts
            );

            if ($status !== 'completed') {
                Log::error("Run did not complete. Final status: {$status}", ['run' => $runStatus]);
                throw new \Exception("Assistant run failed or timed out with status: {$status}");
            }

            // 5. Get Assistant's response
            Log::info("Run completed. Fetching messages from thread {$threadId}");
            $messagesResponse = Http::withHeaders($headers)
                ->get("https://api.openai.com/v1/threads/{$threadId}/messages", ['limit' => 10]);

            $messages = $messagesResponse->json('data') ?? [];

            // Find the last message from the assistant
            $agentReply = "Sorry, I couldn't find a response from the Assistant.";
            foreach ($messages as $msg) {
                if ($msg['role'] === 'assistant') {
                    $agentReply = $msg['content'][0]['text']['value'] ?? 'Assistant sent an empty message.';
                    break; // We found the answer, we're out of the loop
                }
            }

            // Save and send the Agent message
            $agentMessage = $conversation->messages()->create([
                'sender' => 'agent',
                'body' => $agentReply,
            ]);

            Log::info("Broadcasting agent message for conversation {$conversation->id}");
            broadcast(new AgentMessageSent($agentMessage))->toOthers();

            // Make sure the connection is closed thread
            // Http::withHeaders($headers)
            //     ->delete("https://api.openai.com/v1/threads/{$threadId}")
            //     ->throw();

        } catch (Throwable $e) {
            Log::error("OpenAI Chat Job failed: " . $e->getMessage(), [
                'message_id' => $this->message->id,
                'conversation_id' => $this->message->conversation->id ?? 'N/A',
                'error_trace' => $e->getTraceAsString(),
            ]);

           // Send an error message to the user via broadcast
            $errorMessage = $this->message->conversation->messages()->create([
                'sender' => 'agent',
                'body' => "I'm sorry, an error occurred while processing your request. Please try again later.",
            ]);
            broadcast(new AgentMessageSent($errorMessage))->toOthers();
        }
    }
}