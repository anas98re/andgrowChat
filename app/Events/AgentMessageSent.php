<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The message instance.
     *
     * @var \App\Models\Message
     */
    public Message $message;

    /**
     * Create a new event instance.
     */
    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('chat-session.' . $this->message->conversation->session_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
   public function broadcastWith(): array
    {
        \Log::info("Broadcasting AgentMessageSent", ['message_id' => $this->message->id]);
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender' => $this->message->sender,
            'body' => $this->message->body,
            'created_at' => $this->message->created_at->toDateTimeString(),
        ];
    }

}