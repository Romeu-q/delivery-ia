<script setup>
import { formatNumber } from '../format';

defineProps({
    campaign: { type: Object, required: true },
});
</script>

<template>
    <div class="rounded-lg border border-brand-100 bg-brand-50 p-4">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm font-semibold text-brand-700">📣 {{ campaign.name }}</span>
            <span class="rounded-full bg-surface px-2 py-0.5 text-xs text-stone-600">{{ campaign.status }}</span>
        </div>
        <p class="text-sm text-stone-700">
            <strong>{{ formatNumber(campaign.audience_count) }}</strong> clientes no público ·
            {{ campaign.audience_rule }}
        </p>
        <div class="mt-3 rounded-lg bg-surface p-3 shadow-sm">
            <div class="text-xs text-stone-400">Prévia da mensagem</div>
            <div class="font-medium text-stone-900">{{ campaign.message_title }}</div>
            <p class="mt-1 whitespace-pre-line text-sm text-stone-700">{{ campaign.preview || campaign.message_body }}</p>
        </div>
        <p class="mt-2 text-xs text-stone-500">Rascunho salvo. Nenhuma mensagem foi enviada.</p>
    </div>
</template>
