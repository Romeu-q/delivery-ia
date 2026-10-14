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
        env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        env('GEMINI_FALLBACK_MODEL', 'gemini-3.1-flash-lite'),
    ])),

    // Falhas temporárias (429/503): novas tentativas no mesmo modelo antes do fallback, com espera crescente
    'retries_per_model' => (int) env('AI_RETRIES_PER_MODEL', 1),
    'retry_delay_ms' => (int) env('AI_RETRY_DELAY_MS', 1200),

    'temperature' => (float) env('AI_TEMPERATURE', 0.2),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 2048),

    // Nível de raciocínio dos modelos Gemini 3 (minimal | low | medium | high). Vazio = padrão do modelo.
    'thinking_level' => env('AI_THINKING_LEVEL', 'low'),

    // Máximo de rodadas modelo → ferramentas → modelo por pergunta (evita loops e custo descontrolado)
    'max_tool_rounds' => 5,

    // Memória: quantas mensagens anteriores (só texto) são reenviadas ao modelo
    'history_messages' => (int) env('AI_HISTORY_MESSAGES', 6),

    'max_question_length' => 500,

    'rate_limit' => [
        'per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 15),
        'per_day' => (int) env('AI_RATE_LIMIT_PER_DAY', 150),
    ],

    'analytics_cache_ttl' => (int) env('ANALYTICS_CACHE_TTL', 300),

    // Ações com efeito colateral que a IA pode executar. Remover daqui desliga a ferramenta.
    'allowed_actions' => ['create_campaign_draft'],

    // Preço em US$ por 1 milhão de tokens (entrada / saída) para cálculo de custo.
    // Fonte: https://ai.google.dev/gemini-api/docs/pricing — atualizar se o provedor mudar.
    'pricing' => [
        'gemini-3.8-flash' => ['input' => 0.75, 'output' => 3.75], // preço promocional até 31/12/2026
        'gemini-3.5-flash-lite' => ['input' => 0.30, 'output' => 2.50],
        'gemini-3.1-flash-lite' => ['input' => 0.25, 'output' => 1.50],
        'default' => ['input' => 0.75, 'output' => 3.75],
    ],
];
