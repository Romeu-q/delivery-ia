const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export class ApiError extends Error {
    constructor(message, status) {
        super(message);
        this.status = status;
    }
}

async function request(method, url, body = null) {
    const response = await fetch(url, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : null,
    });
    if (response.status === 204) return null;
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw new ApiError(json.message || 'Erro ao comunicar com o servidor.', response.status);
    return json;
}

export const api = {
    dashboard: () => request('GET', '/api/dashboard'),
    conversations: () => request('GET', '/api/conversations'),
    conversation: (id) => request('GET', `/api/conversations/${id}`),
    deleteConversation: (id) => request('DELETE', `/api/conversations/${id}`),
    campaigns: () => request('GET', '/api/campaigns'),
    usage: () => request('GET', '/api/usage'),
    stores: () => request('GET', '/api/stores'),
    selectStore: (id) => request('POST', '/api/stores/select', { store_id: id }),
};

/**
 * Envia a pergunta e lê a resposta em Server-Sent Events.
 * EventSource só faz GET, então usamos fetch + leitura do corpo em stream.
 *
 * @param {{message: string, conversation_id?: string}} payload
 * @param {(event: string, data: object) => void} onEvent
 */
export async function streamChat(payload, onEvent, signal) {
    const response = await fetch('/api/chat', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
        signal,
    });

    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        const message = response.status === 419
            ? 'Sua sessão expirou. Recarregue a página.'
            : body.message || 'Não foi possível enviar a pergunta.';
        throw new ApiError(message, response.status);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });

        // Cada evento SSE termina com uma linha em branco
        let boundary;
        while ((boundary = buffer.indexOf('\n\n')) !== -1) {
            const raw = buffer.slice(0, boundary);
            buffer = buffer.slice(boundary + 2);

            let event = 'message';
            let data = '';
            for (const line of raw.split('\n')) {
                if (line.startsWith('event:')) event = line.slice(6).trim();
                else if (line.startsWith('data:')) data += line.slice(5).trim();
            }
            if (data) onEvent(event, JSON.parse(data));
        }
    }
}
