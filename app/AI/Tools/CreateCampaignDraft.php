<?php

namespace App\AI\Tools;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * Única ferramenta com efeito colateral. Limites deliberados:
 *  - só cria RASCUNHO (status draft) — o envio nunca é feito pela IA;
 *  - o público é calculado pelo backend a partir de uma regra validada, não de uma lista vinda da IA;
 *  - sempre restrita à loja do contexto e limitada por hora para evitar abuso.
 */
class CreateCampaignDraft extends Tool
{
    private const MAX_DRAFTS_PER_HOUR = 10;

    public function name(): string
    {
        return 'create_campaign_draft';
    }

    public function description(): string
    {
        return 'AÇÃO: cria um RASCUNHO de campanha de reativação (não envia nada) para clientes sem pedidos há pelo '
            .'menos N dias. O backend identifica e salva o público e retorna quantos clientes foram encontrados. '
            .'Você deve escrever o título e o texto da mensagem (curto, amigável, em português, pode usar {nome} '
            .'para personalizar). Use somente quando o usuário pedir explicitamente para criar uma campanha.';
    }

    public function readOnly(): bool
    {
        return false;
    }

    protected function properties(): array
    {
        return [
            'min_days_without_order' => ['type' => 'integer', 'description' => 'Público: clientes sem pedir há pelo menos N dias (7 a 365).'],
            'max_days_without_order' => ['type' => 'integer', 'description' => 'Opcional: no máximo M dias sem pedir.'],
            'campaign_name' => ['type' => 'string', 'description' => 'Nome interno da campanha (até 80 caracteres).'],
            'message_title' => ['type' => 'string', 'description' => 'Título da mensagem (até 80 caracteres).'],
            'message_body' => ['type' => 'string', 'description' => 'Texto da mensagem para o cliente (até 500 caracteres).'],
        ];
    }

    protected function required(): array
    {
        return ['min_days_without_order', 'campaign_name', 'message_title', 'message_body'];
    }

    protected function rules(): array
    {
        return [
            'min_days_without_order' => ['required', 'integer', 'min:7', 'max:365'],
            'max_days_without_order' => ['nullable', 'integer', 'max:730', 'gt:min_days_without_order'],
            'campaign_name' => ['required', 'string', 'max:80'],
            'message_title' => ['required', 'string', 'max:80'],
            'message_body' => ['required', 'string', 'max:500'],
        ];
    }

    protected function run(array $args, ToolContext $context): ToolResult
    {
        $recent = Campaign::query()
            ->where('store_id', $context->storeId)
            ->where('created_at', '>=', $context->now->subHour())
            ->count();
        if ($recent >= self::MAX_DRAFTS_PER_HOUR) {
            return ToolResult::error('Limite de rascunhos de campanha por hora atingido. Tente novamente mais tarde.');
        }

        $minDays = (int) $args['min_days_without_order'];
        $maxDays = isset($args['max_days_without_order']) ? (int) $args['max_days_without_order'] : null;
        $audience = $this->analytics->inactiveCustomersQuery($context->storeId, $context->now, $minDays, $maxDays);

        $campaign = DB::transaction(function () use ($args, $context, $audience, $minDays, $maxDays) {
            $campaign = Campaign::query()->create([
                'store_id' => $context->storeId,
                'conversation_id' => $context->conversationId,
                'name' => strip_tags($args['campaign_name']),
                'audience_rule' => ['min_days_without_order' => $minDays, 'max_days_without_order' => $maxDays],
                'audience_count' => 0,
                'message_title' => strip_tags($args['message_title']),
                'message_body' => strip_tags($args['message_body']),
                'status' => 'draft',
            ]);

            // Público salvo direto no banco (INSERT ... SELECT), sem trafegar a lista pela aplicação ou pela IA
            DB::table('campaign_customer')->insertUsing(
                ['campaign_id', 'customer_id'],
                (clone $audience)->selectRaw('?, customers.id', [$campaign->id]),
            );
            $campaign->update(['audience_count' => DB::table('campaign_customer')->where('campaign_id', $campaign->id)->count()]);

            return $campaign;
        });

        $sample = $campaign->customers()->orderByDesc('last_order_at')->limit(3)->pluck('name');

        $card = [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => 'rascunho (não enviada)',
            'audience_count' => $campaign->audience_count,
            'audience_rule' => $maxDays
                ? "Clientes sem pedidos entre {$minDays} e {$maxDays} dias"
                : "Clientes sem pedidos há mais de {$minDays} dias",
            'message_title' => $campaign->message_title,
            'message_body' => $campaign->message_body,
            'preview' => str_replace('{nome}', strtok($sample->first() ?? 'Cliente', ' '), $campaign->message_body),
        ];

        return new ToolResult(
            ['campaign_created' => true, 'not_sent' => true] + collect($card)->except('preview')->all(),
            campaign: $card,
        );
    }
}
