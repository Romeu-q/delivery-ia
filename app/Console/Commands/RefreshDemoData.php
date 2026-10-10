<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A base fictícia é gerada relativa à data do seed. Se a aplicação ficar dias no ar,
 * "este mês" fica sem pedidos; este comando (rodado no boot do container) recria a base
 * quando ela está vazia ou desatualizada.
 */
#[Signature('demo:refresh {--if-stale : Só recria se não houver pedidos nas últimas 48h} {--force : Não pede confirmação}')]
#[Description('Recria a base fictícia de demonstração')]
class RefreshDemoData extends Command
{
    public function handle(): int
    {
        if ($this->option('if-stale') && ! $this->isStale()) {
            $this->info('Base de demonstração atualizada; nada a fazer.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->option('if-stale') && ! $this->confirm('Isso apaga todos os dados. Continuar?')) {
            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        return self::SUCCESS;
    }

    private function isStale(): bool
    {
        if (! Schema::hasTable('orders')) {
            return true;
        }

        $last = DB::table('orders')->max('ordered_at');

        return $last === null || now()->subHours(48)->gt($last);
    }
}
