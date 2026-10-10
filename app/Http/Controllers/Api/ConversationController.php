<?php

namespace App\Http\Controllers\Api;

use App\AI\AssistantService;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $conversations = $this->owned($request)
            ->latest('updated_at')
            ->limit(30)
            ->get(['id', 'title', 'updated_at']);

        return response()->json(['data' => $conversations]);
    }

    public function show(Request $request, string $id, AssistantService $assistant): JsonResponse
    {
        $conversation = $this->owned($request)->with('messages')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'messages' => $conversation->messages->map(fn ($m) => $assistant->present($m)),
            ],
        ]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->owned($request)->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** Um visitante só enxerga as próprias conversas, da loja atual. */
    private function owned(Request $request): Builder
    {
        return Conversation::query()
            ->where('store_id', $request->attributes->get('store')->id)
            ->where('visitor_id', $request->attributes->get('visitor_id'));
    }
}
