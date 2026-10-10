<?php

namespace App\AI\Tools;

use App\Analytics\Period;
use App\Analytics\PeriodResolver;
use App\Analytics\SalesAnalytics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Uma ferramenta que a IA pode chamar (function calling).
 *
 * Fluxo de segurança: argumentos gerados pela IA → validação Laravel (rules) →
 * consulta fixa no SalesAnalytics, filtrada pela loja do ToolContext.
 */
abstract class Tool
{
    public function __construct(protected readonly SalesAnalytics $analytics) {}

    abstract public function name(): string;

    abstract public function description(): string;

    /** Propriedades do JSON Schema (formato OpenAPI aceito pelo Gemini). */
    abstract protected function properties(): array;

    /** Regras de validação dos argumentos. */
    abstract protected function rules(): array;

    abstract protected function run(array $args, ToolContext $context): ToolResult;

    protected function required(): array
    {
        return [];
    }

    /** Ferramentas de leitura podem ter o resultado em cache; ações nunca. */
    public function readOnly(): bool
    {
        return true;
    }

    public function declaration(): array
    {
        $declaration = ['name' => $this->name(), 'description' => $this->description()];

        if ($this->properties() !== []) {
            $declaration['parameters'] = array_filter([
                'type' => 'object',
                'properties' => $this->properties(),
                'required' => $this->required() ?: null,
            ]);
        }

        return $declaration;
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        try {
            $validated = Validator::make($args, $this->rules())->validate();
        } catch (ValidationException $e) {
            return ToolResult::error('Argumentos inválidos: '.implode(' ', $e->validator->errors()->all()));
        }

        if (! $this->readOnly()) {
            return $this->run($validated, $context);
        }

        // Cache guarda só arrays (o projeto não desserializa objetos do cache, por segurança)
        $key = sprintf('analytics:%d:%s:%s', $context->storeId, $this->name(), md5(json_encode($validated)));
        $cached = Cache::remember(
            $key,
            config('ai.analytics_cache_ttl'),
            fn () => $this->run($validated, $context)->toArray(),
        );

        return ToolResult::fromArray($cached);
    }

    // --- Helpers de período, compartilhados pelas ferramentas ---

    protected function periodProperties(string $default = 'this_month'): array
    {
        return [
            'period' => [
                'type' => 'string',
                'enum' => PeriodResolver::KEYS,
                'description' => "Período analisado (padrão: {$default}). 'this_month' vai do dia 1 até agora e é comparado com os mesmos dias do mês anterior. Use 'custom' com start_date e end_date para datas específicas.",
            ],
            'start_date' => ['type' => 'string', 'description' => "Data inicial YYYY-MM-DD (somente com period='custom')."],
            'end_date' => ['type' => 'string', 'description' => "Data final inclusiva YYYY-MM-DD (somente com period='custom')."],
        ];
    }

    protected function periodRules(): array
    {
        return [
            'period' => ['nullable', 'string', Rule::in(PeriodResolver::KEYS)],
            'start_date' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    protected function period(array $args, ToolContext $context, string $default = 'this_month'): Period
    {
        return (new PeriodResolver($context->now))->resolve(
            $args['period'] ?? $default,
            $args['start_date'] ?? null,
            $args['end_date'] ?? null,
        );
    }

    /** Monta a especificação de gráfico consumida pelo front (Chart.js). */
    protected function chart(string $type, string $title, array $labels, array $datasets, string $format = 'currency'): array
    {
        return compact('type', 'title', 'labels', 'datasets', 'format');
    }
}
