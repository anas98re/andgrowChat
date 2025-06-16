<?php

namespace App\Http\Controllers;

use App\Events\VisitorMessageSent;
use App\Jobs\AskCustomGpt;
use App\Models\Conversation;
use App\Models\Message;
use App\Http\Requests\ChatRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    /**
     * Handle incoming chat messages.
     */
    public function store(ChatRequest $request): JsonResponse
    {
        $conversation = null;
        $conversationId = $request->input('conversation_id');
        $sessionId = $request->input('session_id', Str::uuid()); // Generate a UUID if no session_id is provided

        if ($conversationId) {
            $conversation = Conversation::find($conversationId);
        }

        // If no conversation found or provided, create a new one
        if (!$conversation) {
            $conversation = Conversation::firstOrCreate(
                ['session_id' => $sessionId],
                ['session_id' => $sessionId] // Ensure session_id is set for new creation
            );
            // If a new conversation was created, you might want to send a welcome message here
        }

        // Create visitor message
        $message = $conversation->messages()->create([
            'sender' => 'visitor',
            'body' => $request->input('message'),
        ]);
        info('aaaaa');
        // Broadcast visitor message
        broadcast(new VisitorMessageSent($message))->toOthers();
        info('eeee');
        // Dispatch AI processing job
        AskCustomGpt::dispatch($message);
        info('cccc');
        return response()->json([
            'status' => 'success',
            'message' => 'Message received and AI processing initiated.',
            'conversation_id' => $conversation->id,
            'session_id' => $conversation->session_id,
            'sent_message' => $message->toArray(),
        ]);
    }

    /**
     * Get conversation messages.
     */
    public function show(Conversation $conversation): JsonResponse
    {
        // Ensure only messages related to the session_id are retrieved in production
        // For now, we fetch all messages for the given conversation ID.
        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $conversation->messages()->orderBy('created_at')->get(),
        ]);
    }

    public function showBySessionId(string $sessionId): JsonResponse
    {
        $conversation = Conversation::where('session_id', $sessionId)->first();

        if (!$conversation) {
            return response()->json([
                'conversation_id' => null,
                'messages' => [],
            ]);
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $conversation->messages()->orderBy('created_at')->get(),
        ]);
    }
}
