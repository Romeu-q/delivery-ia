<script setup>
import { useTheme } from '../composables/useTheme';

const { theme, toggle } = useTheme();

defineProps({
    storeName: { type: String, default: '' },
    conversations: { type: Array, default: () => [] },
    activeId: { type: String, default: null },
    usage: { type: Object, default: null },
});
const emit = defineEmits(['new', 'open', 'delete', 'close', 'campaigns']);
</script>

<template>
    <aside class="flex h-full w-72 flex-col border-r border-stone-200 bg-surface">
        <!-- Degradê roxo da identidade Prefiro, com detalhe em turquesa -->
        <div class="flex items-center justify-between bg-gradient-to-br from-[#5448c2] via-[#40389b] to-[#38347b] px-4 pt-4 pb-4 text-white">
            <div>
                <div class="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-white/75">
                    <span class="h-1.5 w-1.5 rounded-full bg-accent"></span>
                    Assistente de Performance
                </div>
                <div class="mt-0.5 font-bold">{{ storeName || '…' }}</div>
            </div>
            <button type="button" class="text-white/70 hover:text-white md:hidden" aria-label="Fechar menu" @click="emit('close')">✕</button>
        </div>

        <div class="px-3 pt-3">
            <button
                type="button"
                class="w-full rounded-lg border border-stone-200 px-3 py-2 text-left text-sm font-medium text-stone-700 hover:bg-stone-50"
                @click="emit('new')"
            >+ Nova conversa</button>
            <button
                type="button"
                class="mt-1 w-full rounded-lg px-3 py-2 text-left text-sm text-stone-600 hover:bg-stone-50"
                @click="emit('campaigns')"
            >📣 Campanhas criadas</button>
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

        <div class="border-t border-stone-100 px-4 py-3 text-xs text-stone-500">
            <template v-if="usage">
                <div class="mb-1 font-medium text-stone-600">Consumo de IA hoje</div>
                <div>{{ usage.today.answers }} respostas · {{ Number(usage.today.prompt_tokens) + Number(usage.today.output_tokens) }} tokens</div>
                <div>US$ {{ Number(usage.today.cost_usd).toFixed(4) }} · média {{ ((usage.today.avg_latency_ms || 0) / 1000).toFixed(1) }}s</div>
            </template>
            <button
                type="button"
                class="mt-2 flex w-full items-center justify-center gap-2 rounded-lg border border-stone-200 px-3 py-1.5 text-stone-600 hover:bg-stone-100"
                :aria-label="theme === 'dark' ? 'Usar tema claro' : 'Usar tema escuro'"
                @click="toggle"
            >{{ theme === 'dark' ? '☀️ Tema claro' : '🌙 Tema escuro' }}</button>
        </div>
    </aside>
</template>
