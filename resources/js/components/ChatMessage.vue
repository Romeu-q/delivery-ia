<script setup>
import { computed, ref } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import ChartBlock from './ChartBlock.vue';
import CampaignCard from './CampaignCard.vue';
import DataView from './DataView.vue';

const props = defineProps({
    message: { type: Object, required: true },
    // Sugestões e "tentar de novo" só aparecem na última resposta
    isLast: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['ask', 'retry']);

const showDetails = ref(false);
const showData = ref(false);
const copied = ref(false);

const toolsWithData = computed(() => (meta.value.tools || []).filter((t) => t.data && !t.error));

async function copy() {
    try {
        await navigator.clipboard.writeText(props.message.content);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        // clipboard indisponível (http sem TLS, permissão negada): ignora
    }
}

// A resposta da IA é tratada como conteúdo não confiável: markdown → HTML → sanitizado (evita XSS)
const html = computed(() => DOMPurify.sanitize(marked.parse(props.message.content || '', { breaks: true })));

const meta = computed(() => props.message.meta || {});
const streaming = computed(() => props.message.status === 'streaming');
const steps = computed(() => props.message.steps?.length ? props.message.steps : (meta.value.tools || []).map((t) => t.label));
const verification = computed(() => meta.value.verification);
</script>

<template>
    <div v-if="message.role === 'user'" class="flex justify-end">
        <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-stone-800 px-4 py-2.5 text-sm text-white md:max-w-[70%]">
            {{ message.content }}
        </div>
    </div>

    <div v-else class="flex gap-3">
        <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-500 text-sm font-semibold text-white">IA</div>
        <div class="min-w-0 flex-1 space-y-3">
            <!-- Etapas: quais dados o assistente consultou -->
            <ul v-if="steps.length" class="space-y-1">
                <li v-for="(step, i) in steps" :key="i" class="flex items-center gap-2 text-xs text-stone-500">
                    <span v-if="streaming && i === steps.length - 1 && !message.content" class="h-3 w-3 animate-spin rounded-full border-2 border-brand-500 border-t-transparent"></span>
                    <span v-else class="text-emerald-600">✓</span>
                    {{ step }}
                </li>
            </ul>

            <div v-if="streaming && !message.content && !steps.length" class="flex items-center gap-2 text-sm text-stone-500">
                <span class="h-3 w-3 animate-spin rounded-full border-2 border-brand-500 border-t-transparent"></span>
                Pensando…
            </div>

            <div
                v-if="message.content"
                class="prose-answer rounded-2xl rounded-tl-sm border border-stone-200 bg-white px-4 py-3 text-sm leading-relaxed text-stone-700"
                :class="{ 'typing-cursor': streaming }"
                v-html="html"
            ></div>

            <div v-if="meta.campaign">
                <CampaignCard :campaign="meta.campaign" />
            </div>

            <div v-if="meta.charts?.length" class="grid gap-3" :class="meta.charts.length > 1 ? 'lg:grid-cols-2' : ''">
                <ChartBlock v-for="(chart, i) in meta.charts" :key="i" :chart="chart" />
            </div>

            <div v-if="message.status === 'error'" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                <span>{{ meta.error || 'Não foi possível concluir a resposta.' }}</span>
                <button
                    v-if="isLast && !busy"
                    type="button"
                    class="rounded-md border border-red-300 bg-white px-2 py-0.5 text-xs font-medium hover:bg-red-100"
                    @click="emit('retry')"
                >Tentar novamente</button>
            </div>

            <!-- Rodapé: verificação de números, ações e custo -->
            <div v-if="!streaming && message.status === 'ok'" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-stone-400">
                <span v-if="verification?.status === 'verified'" class="text-emerald-700" title="Todos os números citados foram encontrados nos dados retornados pelo banco">
                    ✓ {{ verification.checked }} números conferidos com o banco
                </span>
                <span
                    v-else-if="verification?.status === 'unverified'"
                    class="text-amber-700"
                    title="A IA citou valores que não aparecem nos dados consultados (pode ser um erro de cópia ou um número sugerido por ela, como um % de desconto). Confira em 'Ver dados consultados'."
                >
                    ⚠ Valores fora dos dados consultados: {{ verification.unverified.join(', ') }}
                </span>
                <button v-if="toolsWithData.length" type="button" class="hover:text-stone-600" @click="showData = !showData">
                    {{ showData ? 'ocultar dados' : 'ver dados consultados' }}
                </button>
                <button type="button" class="hover:text-stone-600" @click="copy">{{ copied ? 'copiado ✓' : 'copiar' }}</button>
                <button v-if="message.model" type="button" class="hover:text-stone-600" @click="showDetails = !showDetails">
                    {{ showDetails ? 'ocultar detalhes' : 'detalhes' }}
                </button>
                <template v-if="showDetails">
                    <span>{{ message.model }}</span>
                    <span>{{ message.tokens }} tokens</span>
                    <span>US$ {{ Number(message.cost_usd).toFixed(5) }}</span>
                    <span>{{ (message.latency_ms / 1000).toFixed(1) }}s</span>
                </template>
            </div>

            <!-- Dados exatamente como vieram do banco -->
            <div v-if="showData" class="space-y-3 rounded-lg border border-stone-200 bg-stone-50 p-3">
                <section v-for="(tool, i) in toolsWithData" :key="i">
                    <h4 class="mb-1 text-xs font-semibold text-stone-700">{{ tool.label }}</h4>
                    <DataView :data="tool.data" />
                </section>
            </div>

            <!-- Próximas perguntas sugeridas -->
            <div v-if="isLast && !busy && meta.followups?.length" class="flex flex-wrap gap-2 pt-1">
                <button
                    v-for="s in meta.followups"
                    :key="s"
                    type="button"
                    class="rounded-full border border-stone-200 bg-white px-3 py-1 text-xs text-stone-600 hover:border-brand-500 hover:text-brand-700"
                    @click="emit('ask', s)"
                >{{ s }}</button>
            </div>
        </div>
    </div>
</template>
