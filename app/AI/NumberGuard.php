<?php

namespace App\AI;

/**
 * Verificação pós-resposta: todo número citado pela IA precisa existir nos dados que as
 * ferramentas retornaram do banco (ou na própria pergunta). Números que não batem são
 * sinalizados na interface — é a segunda camada de proteção contra números inventados.
 */
class NumberGuard
{
    /** Inteiros pequenos são usados em contagens de texto ("3 ações", "2 opções"). */
    private const FREE_INTEGER_MAX = 5;

    /** Tolerância para arredondamentos (ex.: 23,48% citado como 23,5% ou 23%). */
    private const TOLERANCES = [0.005, 0.05, 0.5];

    /**
     * @param  array  $sources  resultados das ferramentas (qualquer profundidade)
     * @return array{status: string, checked: int, unverified: string[]}
     */
    public function check(string $answer, array $sources, string $question = ''): array
    {
        $known = [];
        array_walk_recursive($sources, function ($value) use (&$known) {
            if (is_int($value) || is_float($value)) {
                $known[] = abs((float) $value);
            } elseif (is_string($value)) {
                foreach (self::extract($value) as [, $number]) {
                    $known[] = $number;
                }
            }
        });
        foreach (self::extract($question) as [, $number]) {
            $known[] = $number;
        }

        $checked = 0;
        $unverified = [];
        foreach (self::extract(self::stripIgnorable($answer)) as [$raw, $number]) {
            if (self::isFree($number)) {
                continue;
            }
            $checked++;
            if (! self::matches($number, $known)) {
                $unverified[] = $raw;
            }
        }

        $unverified = array_values(array_unique($unverified));

        return [
            'status' => match (true) {
                $checked === 0 => 'no_numbers',
                $unverified === [] => 'verified',
                default => 'unverified',
            },
            'checked' => $checked,
            'unverified' => $unverified,
        ];
    }

    /**
     * Extrai números em formato brasileiro (1.234,56 / 14,2 / 37) e também 1234.56.
     *
     * @return array<int, array{0: string, 1: float}>
     */
    public static function extract(string $text): array
    {
        preg_match_all('/(?<![\d.,])\d{1,3}(?:\.\d{3})+(?:,\d+)?(?![\d])|(?<![\d.,])\d+(?:[.,]\d+)?(?![\d])/u', $text, $matches);

        return array_map(function (string $raw) {
            if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $raw)) {
                $normalized = str_replace(['.', ','], ['', '.'], $raw);   // 1.234,56
            } else {
                $normalized = str_replace(',', '.', $raw);                // 14,2 ou 14.2
            }

            return [$raw, abs((float) $normalized)];
        }, $matches[0]);
    }

    /** Remove datas, horas, anos e marcadores de lista numerada, que não são métricas. */
    private static function stripIgnorable(string $text): string
    {
        $patterns = [
            '/\b\d{1,2}\/\d{1,2}(\/\d{2,4})?\b/u',        // 06/09/2026, 06/09
            '/\b\d{4}-\d{2}(-\d{2})?\b/u',               // 2026-09-01
            '/\b\d{1,2}(:\d{2})\b/u',                     // 19:30
            '/^\s*\d+[.)]\s/mu',                          // "1. " em listas
            '/\b(19|20)\d{2}\b/u',                        // anos
        ];

        return preg_replace($patterns, ' ', $text);
    }

    private static function isFree(float $number): bool
    {
        return $number <= self::FREE_INTEGER_MAX && floor($number) === $number;
    }

    private static function matches(float $number, array $known): bool
    {
        foreach ($known as $value) {
            foreach (self::TOLERANCES as $tolerance) {
                if (abs($number - $value) <= $tolerance + 1e-9) {
                    // Tolerâncias maiores só valem quando o número citado está arredondado
                    if ($tolerance >= 0.5 && floor($number) !== $number) {
                        continue;
                    }
                    if ($tolerance >= 0.05 && round($number, 1) !== $number) {
                        continue;
                    }

                    return true;
                }
            }
        }

        return false;
    }
}
