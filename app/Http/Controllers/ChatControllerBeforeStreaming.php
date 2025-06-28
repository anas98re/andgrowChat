<?php

namespace App\Http\Controllers;

use App\Events\VisitorMessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Http\Requests\ChatRequest;
use App\Services\OpenAiChatService; 
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /**
     * Handle incoming chat messages.
     * This will now process the request synchronously.
     */
    public function store(ChatRequest $request, OpenAiChatService $chatService): JsonResponse
    {
        $validated = $request->validated();
        $conversation = null;
        $conversationId = $request->input('conversation_id');
        $sessionId = $request->input('session_id', (string) Str::uuid());

        if ($conversationId) {
            $conversation = Conversation::find($conversationId);
        }

        if (!$conversation) {
            $conversation = Conversation::firstOrCreate(['session_id' => $sessionId]);
        }

        $visitorMessage = $conversation->messages()->create([
            'sender' => 'visitor',
            'body' => $validated['message'],
        ]);
        Log::info('ChatController: Visitor message created.', ['id' => $visitorMessage->id]);

        // Broadcast visitor message to other tabs/devices if needed
        broadcast(new VisitorMessageSent($visitorMessage))->toOthers();
        Log::info('ChatController: VisitorMessageSent broadcasted.');

        // --- NEW SYNCHRONOUS LOGIC ---
        // Instead of dispatching a job, we call the service directly.
        // The API response will now wait for this to complete.
        $chatService->getResponseAndBroadcast($visitorMessage);
        // --- END OF NEW LOGIC ---

        return response()->json([
            'status' => 'success',
            'message' => 'Message processed synchronously.', // الرسالة تغيرت لتعكس الطريقة الجديدة
            'conversation_id' => $conversation->id,
            'session_id' => $conversation->session_id,
            'sent_message' => $visitorMessage->toArray(),
        ]);
    }

    /**
     * Get conversation messages.
     */
    public function show(Conversation $conversation): JsonResponse
    {
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



//   /usr/local/bin/php /home/anaplucolsemi/andgrow-chat/artisan queue:work --queue=chat_ai,default --stop-when-empty >> /home/anaplucolsemi/andgrow-chat/storage/logs/cron.log 2>&1