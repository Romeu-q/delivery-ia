<?php

namespace App\Http\Controllers\Api;

use App\AI\AssistantService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatRequest;
use App\Models\Conversation;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /**
     * Responde em Server-Sent Events: conversation → tool* → delta* / chart* / campaign → done | error.
     */
    public function __invoke(ChatRequest $request, AssistantService $assistant): StreamedResponse
    {
        $store = $request->attributes->get('store');
        $visitorId = $request->attributes->get('visitor_id');
        $question = $request->validated('message');

        $conversation = $request->validated('conversation_id')
            ? Conversation::query()
                ->where('store_id', $store->id)
                ->where('visitor_id', $visitorId)
                ->findOrFail($request->validated('conversation_id'))
            : Conversation::query()->create([
                'store_id' => $store->id,
                'visitor_id' => $visitorId,
                'title' => Str::limit($question, 60),
            ]);

        return response()->stream(function () use ($assistant, $conversation, $question) {
            set_time_limit(180);

            $emit = function (string $event, array $data) {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $emit('conversation', ['id' => $conversation->id, 'title' => $conversation->title]);
            $assistant->ask($conversation, $question, $emit);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no', // desliga buffer de proxies (nginx), senão o streaming chega de uma vez
        ]);
    }
}
