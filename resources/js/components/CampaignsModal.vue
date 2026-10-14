<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '../api';
import { formatNumber } from '../format';

/** Rascunhos de campanha criados pela IA. Nada é enviado: o gestor copia a mensagem e usa onde quiser. */
const emit = defineEmits(['close']);

const campaigns = ref([]);
const loading = ref(true);
const error = ref(null);
const copiedId = ref(null);

async function load() {
    loading.value = true;
    try {
        campaigns.value = (await api.campaigns()).data;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function audienceText(rule) {
    return rule?.max_days_without_order
        ? `sem pedir entre ${rule.min_days_without_order} e ${rule.max_days_without_order} dias`
        : `sem pedir há mais de ${rule?.min_days_without_order} dias`;
}

async function copy(c) {
    try {
        await navigator.clipboard.writeText(`${c.message_title}\n\n${c.message_body}`);
        copiedId.value = c.id;
        setTimeout(() => (copiedId.value = null), 1500);
    } catch {
        // clipboard indisponível: ignora
    }
}

const onKey = (e) => e.key === 'Escape' && emit('close');
onMounted(() => {
    load();
    window.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-black/30 p-0 sm:items-center sm:p-4" @click.self="emit('close')">
        <div role="dialog" aria-modal="true" aria-labelledby="campaigns-title" class="flex max-h-[85dvh] w-full max-w-2xl flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            <header class="flex items-center justify-between border-b border-stone-100 px-5 py-3">
                <div>
                    <h2 id="campaigns-title" class="font-semibold text-stone-900">Campanhas (rascunhos)</h2>
                    <p class="text-xs text-stone-500">Criadas pelo assistente. Nenhuma foi enviada.</p>
                </div>
                <button type="button" class="text-stone-400 hover:text-stone-700" aria-label="Fechar" @click="emit('close')">✕</button>
            </header>

            <div class="flex-1 overflow-y-auto px-5 py-4">
                <p v-if="loading" class="text-sm text-stone-500">Carregando…</p>
                <p v-else-if="error" class="text-sm text-red-700">{{ error }}</p>
                <div v-else-if="!campaigns.length" class="py-6 text-center text-sm text-stone-500">
                    Nenhuma campanha ainda. Peça no chat, por exemplo:
                    <em>"Crie uma campanha para clientes que não compram há mais de 30 dias."</em>
                </div>
                <ul v-else class="space-y-3">
                    <li v-for="c in campaigns" :key="c.id" class="rounded-xl border border-stone-200 p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium text-stone-900">{{ c.name }}</span>
                            <span class="text-xs text-stone-400">{{ new Date(c.created_at).toLocaleString('pt-BR') }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-stone-500">
                            <strong class="text-stone-700">{{ formatNumber(c.audience_count) }}</strong> clientes · {{ audienceText(c.audience_rule) }}
                        </p>
                        <div class="mt-2 rounded-lg bg-stone-50 p-3">
                            <div class="text-sm font-medium text-stone-800">{{ c.message_title }}</div>
                            <p class="mt-1 whitespace-pre-line text-sm text-stone-600">{{ c.message_body }}</p>
                        </div>
                        <button type="button" class="mt-2 text-xs font-medium text-brand-600 hover:text-brand-700" @click="copy(c)">
                            {{ copiedId === c.id ? 'Mensagem copiada ✓' : 'Copiar mensagem' }}
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
