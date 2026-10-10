import { reactive, readonly } from 'vue';
import { api, streamChat } from '../api';

/**
 * Estado do chat compartilhado pelos componentes (sem Pinia: um composable basta neste tamanho).
 */
const state = reactive({
    conversationId: null,
    conversations: [],
    messages: [],
    loading: false,
    error: null,
});

let controller = null;

function assistantPlaceholder() {
    return {
        id: `tmp-${Date.now()}`,
        role: 'assistant',
        content: '',
        status: 'streaming',
        steps: [],
        meta: { charts: [], campaign: null },
    };
}

async function refreshConversations() {
    try {
        state.conversations = (await api.conversations()).data;
    } catch {
        // lista lateral é secundária: não interrompe o uso do chat
    }
}

async function openConversation(id) {
    if (state.loading) return;
    state.error = null;
    try {
        const { data } = await api.conversation(id);
        state.conversationId = data.id;
        state.messages = data.messages;
    } catch (e) {
        state.error = e.message;
    }
}

function newConversation() {
    if (state.loading) return;
    state.conversationId = null;
    state.messages = [];
    state.error = null;
}

async function deleteConversation(id) {
    await api.deleteConversation(id);
    if (state.conversationId === id) newConversation();
    await refreshConversations();
}

async function send(text) {
    const message = text.trim();
    if (!message || state.loading) return;

    state.error = null;
    state.loading = true;
    state.messages.push({ id: `u-${Date.now()}`, role: 'user', content: message, status: 'ok' });
    state.messages.push(assistantPlaceholder());
    // Pega a versão reativa (proxy) do objeto recém-inserido para as atualizações renderizarem
    const answer = state.messages[state.messages.length - 1];

    controller = new AbortController();

    try {
        await streamChat(
            { message, conversation_id: state.conversationId },
            (event, data) => {
                switch (event) {
                    case 'conversation':
                        state.conversationId = data.id;
                        break;
                    case 'tool':
                        answer.steps.push(data.label);
                        break;
                    case 'delta':
                        answer.content += data.text;
                        break;
                    case 'chart':
                        answer.meta.charts.push(data);
                        break;
                    case 'campaign':
                        answer.meta.campaign = data;
                        break;
                    case 'done':
                        Object.assign(answer, data, { steps: answer.steps });
                        break;
                    case 'error':
                        answer.status = 'error';
                        answer.meta.error = data.message;
                        if (!answer.content) answer.content = '';
                        break;
                }
            },
            controller.signal,
        );
        if (answer.status === 'streaming') answer.status = 'ok';
    } catch (e) {
        if (e.name === 'AbortError') {
            answer.status = 'error';
            answer.meta.error = 'Resposta interrompida.';
        } else {
            // Erro antes de o servidor aceitar a pergunta (validação, limite, rede)
            state.messages.splice(state.messages.indexOf(answer), 1);
            state.error = e.message || 'Falha de conexão. Verifique sua internet e tente novamente.';
        }
    } finally {
        state.loading = false;
        controller = null;
        refreshConversations();
    }
}

function stop() {
    controller?.abort();
}

export function useChat() {
    return {
        state: readonly(state),
        send,
        stop,
        openConversation,
        newConversation,
        deleteConversation,
        refreshConversations,
    };
}
