<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\JsonResponse;

/**
 * Observabilidade básica do consumo de IA (hoje e últimos 7 dias), a partir das mensagens salvas.
 */
class UsageController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $stats = fn ($since) => Message::query()
            ->where('role', 'assistant')
            ->where('created_at', '>=', $since)
            ->selectRaw("COUNT(*) AS answers,
                SUM(status = 'error') AS errors,
                COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
                COALESCE(SUM(output_tokens), 0) AS output_tokens,
                COALESCE(SUM(cost_usd), 0) AS cost_usd,
                ROUND(AVG(latency_ms)) AS avg_latency_ms")
            ->first();

        $byModel = Message::query()
            ->where('role', 'assistant')
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('model')
            ->selectRaw('model, COUNT(*) AS answers, SUM(prompt_tokens + output_tokens) AS tokens, SUM(cost_usd) AS cost_usd')
            ->groupBy('model')
            ->get();

        return response()->json([
            'today' => $stats(now()->startOfDay()),
            'last_7_days' => $stats(now()->subDays(7)),
            'by_model_last_7_days' => $byModel,
        ]);
    }
}
