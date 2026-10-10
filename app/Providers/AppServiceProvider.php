<?php

namespace App\Providers;

use App\AI\Gemini\GeminiClient;
use App\AI\Tools;
use App\AI\Tools\ToolRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeminiClient::class, fn () => new GeminiClient(
            config('ai.gemini.api_key'),
            config('ai.gemini.base_url'),
            config('ai.gemini.timeout'),
        ));

        // Lista fechada de ferramentas disponíveis para a IA
        $this->app->singleton(ToolRegistry::class, fn ($app) => new ToolRegistry(array_map(
            fn (string $class) => $app->make($class),
            [
                Tools\GetSalesSummary::class,
                Tools\GetSalesTimeseries::class,
                Tools\GetSalesByWeekday::class,
                Tools\GetSalesByHour::class,
                Tools\GetTopProducts::class,
                Tools\GetProductTrends::class,
                Tools\GetCategoryPerformance::class,
                Tools\GetTopCustomers::class,
                Tools\GetInactiveCustomers::class,
                Tools\GetChurnedCustomers::class,
                Tools\DetectAnomalies::class,
                Tools\CreateCampaignDraft::class,
            ],
        )));
    }

    public function boot(): void
    {
        // Protege custo e disponibilidade da IA: limite por minuto (visitante) e por dia (IP)
        RateLimiter::for('chat', fn (Request $request) => [
            Limit::perMinute(config('ai.rate_limit.per_minute'))
                ->by('chat-min:'.$request->session()->get('visitor_id', $request->ip()))
                ->response(fn () => response()->json([
                    'message' => 'Muitas perguntas em sequência. Aguarde um minuto e tente novamente.',
                ], 429)),
            Limit::perDay(config('ai.rate_limit.per_day'))
                ->by('chat-day:'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Limite diário de perguntas atingido para este acesso.',
                ], 429)),
        ]);
    }
}
