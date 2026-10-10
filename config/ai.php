<?php

return [

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
    ],

    // Ordem de tentativa: se o principal falhar antes de começar a responder (429, 5xx, timeout),
    // o próximo da lista é usado.
    'models' => array_values(array_filter([
        env('GEMINI_MODEL', 'gemini-2.5-flash'),
        env('GEMINI_FALLBACK_MODEL', 'gemini-2.5-flash-lite'),
    ])),

    'temperature' => (float) env('AI_TEMPERATURE', 0.2),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 2048),

    // Máximo de rodadas modelo → ferramentas → modelo por pergunta (evita loops e custo descontrolado)
    'max_tool_rounds' => 5,

    // Memória: quantas mensagens anteriores (só texto) são reenviadas ao modelo
    'history_messages' => (int) env('AI_HISTORY_MESSAGES', 6),

    'max_question_length' => 500,

    'rate_limit' => [
        'per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 8),
        'per_day' => (int) env('AI_RATE_LIMIT_PER_DAY', 150),
    ],

    'analytics_cache_ttl' => (int) env('ANALYTICS_CACHE_TTL', 300),

    // Ações com efeito colateral que a IA pode executar. Remover daqui desliga a ferramenta.
    'allowed_actions' => ['create_campaign_draft'],

    // Preço em US$ por 1 milhão de tokens (entrada / saída) para cálculo de custo.
    // Fonte: https://ai.google.dev/gemini-api/docs/pricing — atualizar se o provedor mudar.
    'pricing' => [
        'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
        'gemini-2.5-flash-lite' => ['input' => 0.10, 'output' => 0.40],
        'gemini-2.5-pro' => ['input' => 1.25, 'output' => 10.00],
        'default' => ['input' => 0.30, 'output' => 2.50],
    ],
];
