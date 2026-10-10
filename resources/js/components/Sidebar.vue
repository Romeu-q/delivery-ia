<script setup>
defineProps({
    storeName: { type: String, default: '' },
    conversations: { type: Array, default: () => [] },
    activeId: { type: String, default: null },
    usage: { type: Object, default: null },
});
const emit = defineEmits(['new', 'open', 'delete', 'close']);
</script>

<template>
    <aside class="flex h-full w-72 flex-col border-r border-stone-200 bg-white">
        <div class="flex items-center justify-between px-4 pt-4 pb-3">
            <div>
                <div class="text-xs uppercase tracking-wide text-stone-400">Assistente de Performance</div>
                <div class="font-semibold text-stone-900">{{ storeName || '…' }}</div>
            </div>
            <button type="button" class="text-stone-400 hover:text-stone-700 md:hidden" aria-label="Fechar menu" @click="emit('close')">✕</button>
        </div>

        <div class="px-3">
            <button
                type="button"
                class="w-full rounded-lg border border-stone-200 px-3 py-2 text-left text-sm font-medium text-stone-700 hover:bg-stone-50"
                @click="emit('new')"
            >+ Nova conversa</button>
        </div>

        <nav class="mt-3 flex-1 overflow-y-auto px-3" aria-label="Histórico de conversas">
            <p v-if="!conversations.length" class="px-2 text-xs text-stone-400">Suas conversas aparecem aqui.</p>
            <ul class="space-y-0.5">
                <li v-for="c in conversations" :key="c.id" class="group flex items-center">
                    <button
                        type="button"
                        class="flex-1 truncate rounded-lg px-2 py-1.5 text-left text-sm"
                        :class="c.id === activeId ? 'bg-brand-50 text-brand-700' : 'text-stone-600 hover:bg-stone-50'"
                        :title="c.title"
                        @click="emit('open', c.id)"
                    >{{ c.title }}</button>
                    <button
                        type="button"
                        class="px-1 text-xs text-stone-300 opacity-0 group-hover:opacity-100 hover:text-red-600"
                        aria-label="Excluir conversa"
                        @click="emit('delete', c.id)"
                    >✕</button>
                </li>
            </ul>
        </nav>

        <div v-if="usage" class="border-t border-stone-100 px-4 py-3 text-xs text-stone-500">
            <div class="mb-1 font-medium text-stone-600">Consumo de IA hoje</div>
            <div>{{ usage.today.answers }} respostas · {{ Number(usage.today.prompt_tokens) + Number(usage.today.output_tokens) }} tokens</div>
            <div>US$ {{ Number(usage.today.cost_usd).toFixed(4) }} · média {{ ((usage.today.avg_latency_ms || 0) / 1000).toFixed(1) }}s</div>
        </div>
    </aside>
</template>
