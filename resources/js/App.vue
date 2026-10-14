<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from './api';
import { useChat } from './composables/useChat';
import Sidebar from './components/Sidebar.vue';
import KpiBar from './components/KpiBar.vue';
import ChatMessage from './components/ChatMessage.vue';
import ChatInput from './components/ChatInput.vue';
import CampaignsModal from './components/CampaignsModal.vue';

const { state, send, stop, retry, openConversation, newConversation, deleteConversation, refreshConversations } = useChat();

const dashboard = ref(null);
const usage = ref(null);
const sidebarOpen = ref(false);
const campaignsOpen = ref(false);
const stores = ref([]);
const storeId = ref(null);
const switching = ref(false);
const scroller = ref(null);
const input = ref(null);

async function loadDashboard() {
    try {
        dashboard.value = await api.dashboard();
    } catch {
        dashboard.value = null;
    }
}

async function loadStores() {
    try {
        const res = await api.stores();
        stores.value = res.data;
        storeId.value = res.current_id;
    } catch {
        stores.value = [];
    }
}

/** Troca de loja: o servidor valida e guarda na sessão; a tela recarrega dados e conversas da nova loja. */
async function switchStore(id) {
    if (state.loading || id === storeId.value) return;
    switching.value = true;
    try {
        await api.selectStore(id);
        storeId.value = id;
        sidebarOpen.value = false;
        newConversation();
        await Promise.all([loadDashboard(), refreshConversations()]);
    } finally {
        switching.value = false;
    }
}

async function loadUsage() {
    try {
        usage.value = await api.usage();
    } catch {
        usage.value = null;
    }
}

function ask(question) {
    sidebarOpen.value = false;
    send(question);
}

function open(id) {
    sidebarOpen.value = false;
    openConversation(id);
}

function startNew() {
    sidebarOpen.value = false;
    newConversation();
    nextTick(() => input.value?.focus());
}

// Mantém a conversa rolada até o fim enquanto a resposta chega
watch(
    () => [state.messages.length, state.messages[state.messages.length - 1]?.content],
    () => nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight })),
);

watch(() => state.loading, (loading) => {
    if (!loading) loadUsage();
});

// Esc interrompe a resposta em andamento
function onKeydown(e) {
    if (e.key === 'Escape' && state.loading && !campaignsOpen.value) stop();
}

function openCampaigns() {
    sidebarOpen.value = false;
    campaignsOpen.value = true;
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    loadStores();
    loadDashboard();
    loadUsage();
    refreshConversations();
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div class="flex h-dvh overflow-hidden">
        <!-- Sidebar: fixa no desktop, gaveta no celular -->
        <div
            class="fixed inset-y-0 left-0 z-30 transition-transform md:static md:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <Sidebar
                :stores="stores"
                :store-id="storeId"
                :switching="switching"
                :conversations="state.conversations"
                :active-id="state.conversationId"
                :usage="usage"
                @new="startNew"
                @open="open"
                @delete="deleteConversation"
                @close="sidebarOpen = false"
                @campaigns="openCampaigns"
                @switch-store="switchStore"
            />
        </div>
        <div v-if="sidebarOpen" class="fixed inset-0 z-20 bg-black/30 md:hidden" @click="sidebarOpen = false"></div>

        <main class="flex min-w-0 flex-1 flex-col">
            <header class="flex items-center gap-3 border-b border-stone-200 bg-surface px-4 py-2 md:hidden">
                <button type="button" class="text-stone-600" aria-label="Abrir menu" @click="sidebarOpen = true">☰</button>
                <span class="truncate text-sm font-semibold">{{ dashboard?.store?.name || 'Assistente' }}</span>
            </header>

            <KpiBar :dashboard="dashboard" @ask="ask" />

            <div ref="scroller" class="flex-1 overflow-y-auto">
                <div class="mx-auto max-w-3xl space-y-6 px-4 py-6">
                    <!-- Tela inicial com sugestões de perguntas -->
                    <div v-if="!state.messages.length" class="py-6 text-center">
                        <h1 class="text-2xl font-semibold text-stone-900">Como posso ajudar seu delivery hoje?</h1>
                        <p class="mt-2 text-sm text-stone-500">
                            Respostas geradas por IA em tempo real, usando somente números consultados no banco de dados.
                        </p>
                        <div class="mt-6 grid gap-2 text-left sm:grid-cols-2">
                            <button
                                v-for="s in dashboard?.suggestions || []"
                                :key="s"
                                type="button"
                                class="rounded-xl border border-stone-200 bg-surface px-4 py-3 text-sm text-stone-700 hover:border-brand-500 hover:text-brand-700"
                                @click="ask(s)"
                            >{{ s }}</button>
                        </div>
                    </div>

                    <ChatMessage
                        v-for="(m, i) in state.messages"
                        :key="m.id"
                        :message="m"
                        :is-last="i === state.messages.length - 1"
                        :busy="state.loading"
                        @ask="ask"
                        @retry="retry"
                    />
                </div>
            </div>

            <div class="border-t border-stone-200 bg-stone-50 px-4 py-3">
                <div class="mx-auto max-w-3xl">
                    <div v-if="state.error" role="alert" class="mb-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ state.error }}
                    </div>
                    <ChatInput ref="input" :loading="state.loading" @send="ask" @stop="stop" />
                    <p class="mt-1.5 text-center text-xs text-stone-400">
                        Os números vêm do banco de dados; a IA interpreta e recomenda. Campanhas são criadas apenas como rascunho.
                    </p>
                </div>
            </div>
        </main>

        <CampaignsModal v-if="campaignsOpen" @close="campaignsOpen = false" />
    </div>
</template>
