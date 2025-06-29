<?php

// File: app/Http/Controllers/ChatController.php

namespace App\Http\Controllers;

use App\Events\VisitorMessageSent;
use App\Models\Conversation;
use App\Http\Requests\ChatRequest;
use App\Services\OpenAiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function store(ChatRequest $request, OpenAiChatService $chatService): StreamedResponse
    {
        $validated = $request->validated();
        $conversation = Conversation::firstOrCreate(
            ['session_id' => $request->input('session_id', (string) Str::uuid())]
        );

        $visitorMessage = $conversation->messages()->create([
            'sender' => 'visitor',
            'body' => $validated['message'],
        ]);

        broadcast(new VisitorMessageSent($visitorMessage))->toOthers();

        // --- NEW: Quick check for simple greetings to control the UI ---
        $messageBody = strtolower(trim($validated['message']));
       $simpleQueries = config('chatbot.simple_queries', []); // Load the list from config/chatbot.php
        
        $isComplexQuery = true; // Assume the query is complex by default
        foreach ($simpleQueries as $greeting) {
            // If the message contains a simple greeting or identity question
            if (str_contains($messageBody, $greeting)) {
                $isComplexQuery = false;
                break;
            }
        }

        return new StreamedResponse(function () use ($chatService, $visitorMessage, $isComplexQuery) {
            
            // --- NEW: Send an initial 'start-processing' event ONLY for complex queries ---
            if ($isComplexQuery) {
                echo "event: start-processing\n";
                echo "data: Starting complex query process\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }

            $streamCallback = function (array $chunk) {
                if ($chunk['type'] === 'text') {
                    echo "data: " . json_encode(['text' => $chunk['data']]) . "\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }
            };
            
            try {
                // Pass the new variable to the service
                $chatService->streamResponse($visitorMessage, $streamCallback);
                echo "event: end\n";
                echo "data: Stream finished\n\n";
                
            } catch (\Throwable $e) {
                Log::error('Streaming Error in Controller', ['message' => $e->getMessage()]);
                echo "event: error\n";
                echo "data: " . json_encode(['error' => 'An error occurred.']) . "\n\n";
            } finally {
                if (ob_get_level() > 0) ob_flush();
                flush();
            }

        }, 200, [
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }

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

        // Safely handle cases where the conversation doesn't exist
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