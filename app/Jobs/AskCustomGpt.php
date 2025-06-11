<?php

namespace App\Jobs;

use App\Events\AgentMessageSent;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OpenAI\Laravel\Facades\OpenAI; // we should make sure the interface is imported

class AskCustomGpt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Message $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->onQueue('chat_ai'); // To specify a queue for AI (optional)
    }

    public function handle(): void
    {
        try {
            // Ensure the conversation ID exists and is valid
            if (!$this->message->conversation) {
                // Log or handle error if conversation not found
                \Log::error("Conversation not found for message ID: " . $this->message->id);
                return;
            }

            // Call OpenAI Custom GPT
            $response = OpenAI::chat()->create([
                'model' => env('OPENAI_GPT_ID'), // This is the ID of the Custom GPT
                'messages' => [
                    ['role' => 'user', 'content' => $this->message->body],
                ],
            ]);

            // Extract agent's reply
            $agentReply = $response->choices[0]->message->content ?? "Sorry, I couldn't get a response from the AI.";

            // Create agent's message in the database
            $agentMessage = $this->message->conversation->messages()->create([
                'sender' => 'agent',
                'body' => $agentReply,
            ]);

            // Broadcast agent's message
            broadcast(new AgentMessageSent($agentMessage))->toOthers();

        } catch (\Exception $e) {
            // Log the exception for debugging
            \Log::error("OpenAI Chat Job failed: " . $e->getMessage(), [
                'message_id' => $this->message->id,
                'error_trace' => $e->getTraceAsString(),
            ]);
            // Optionally, send an error message back to the user
            $errorMessage = "I'm sorry, an error occurred while processing your request. Please try again later.";
            $this->message->conversation->messages()->create([
                'sender' => 'agent',
                'body' => $errorMessage,
            ]);
            broadcast(new AgentMessageSent($this->message->conversation->messages()->latest()->first()))->toOthers();
        }
    }
}
