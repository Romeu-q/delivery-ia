<?php

namespace App\Console\Commands;

use Database\Seeders\DeliveryDemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A base fictícia é gerada relativa à data do seed; com o passar dos dias "este mês" ficaria sem
 * pedidos. Este comando regera pedidos, clientes e produtos (agendado diariamente e rodado no boot),
 * mantendo conversas e campanhas. Tudo numa transação: quem estiver usando nunca vê a base pela metade.
 */
#[Signature('demo:refresh {--if-stale : Só regera se não houver pedidos nas últimas 24h} {--force : Não pede confirmação}')]
#[Description('Regera a base fictícia de demonstração (pedidos, clientes e produtos)')]
class RefreshDemoData extends Command
{
    public function handle(): int
    {
        if ($this->option('if-stale') && ! $this->isStale()) {
            $this->info('Base de demonstração atualizada; nada a fazer.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->option('if-stale') && ! $this->confirm('Isso substitui pedidos, clientes e produtos. Continuar?')) {
            return self::FAILURE;
        }

        DB::transaction(function () {
            // Ordem respeita as chaves estrangeiras (campaign_customer sai em cascata com customers)
            DB::table('order_items')->delete();
            DB::table('orders')->delete();
            DB::table('customers')->delete();
            DB::table('products')->delete();

            $seeder = new DeliveryDemoSeeder;
            $seeder->setCommand($this);
            $seeder->run();
        });

        // Resultados analíticos em cache se referem à base antiga
        Cache::flush();

        return self::SUCCESS;
    }

    private function isStale(): bool
    {
        if (! Schema::hasTable('orders')) {
            return true;
        }

        // Lojas mudaram no seeder (ex.: deploy que adicionou lojas): regera mesmo com pedidos recentes
        $storesWithOrders = DB::table('orders')->distinct()->count('store_id');
        if ($storesWithOrders !== DeliveryDemoSeeder::storeCount()) {
            return true;
        }

        $last = DB::table('orders')->max('ordered_at');

        return $last === null || now()->subHours(24)->gt($last);
    }
}
