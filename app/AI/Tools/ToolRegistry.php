<?php

namespace App\AI\Tools;

use Throwable;

/**
 * Lista fechada de ferramentas expostas à IA. Qualquer nome fora desta lista é recusado.
 * Ferramentas de ação só entram se estiverem em config('ai.allowed_actions').
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            if (! $tool->readOnly() && ! in_array($tool->name(), config('ai.allowed_actions', []), true)) {
                continue;
            }
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function declarations(): array
    {
        return array_values(array_map(fn (Tool $t) => $t->declaration(), $this->tools));
    }

    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function execute(string $name, array $args, ToolContext $context): ToolResult
    {
        $tool = $this->tools[$name] ?? null;
        if (! $tool) {
            return ToolResult::error("Ferramenta '{$name}' não existe ou não é permitida.");
        }

        try {
            return $tool->execute($args, $context);
        } catch (Throwable $e) {
            report($e);

            // Detalhes internos (SQL, stack) nunca vão para a IA nem para o usuário
            return ToolResult::error('Falha ao consultar os dados. Informe ao usuário que não foi possível obter essa informação.');
        }
    }
}
