<script setup>
import { nextTick, ref } from 'vue';

const props = defineProps({
    loading: { type: Boolean, default: false },
    maxLength: { type: Number, default: 500 },
});
const emit = defineEmits(['send', 'stop']);

const text = ref('');
const textarea = ref(null);

function autoResize() {
    const el = textarea.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

async function submit() {
    if (props.loading || !text.value.trim()) return;
    emit('send', text.value);
    text.value = '';
    await nextTick();
    autoResize();
}

function onKeydown(e) {
    // Enter envia; Shift+Enter quebra linha
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submit();
    }
}

defineExpose({ focus: () => textarea.value?.focus() });
</script>

<template>
    <form class="flex items-end gap-2 rounded-2xl border border-stone-300 bg-white p-2 shadow-sm focus-within:border-brand-500" @submit.prevent="submit">
        <textarea
            ref="textarea"
            v-model="text"
            rows="1"
            :maxlength="maxLength"
            placeholder="Pergunte sobre vendas, produtos ou clientes…"
            aria-label="Pergunta"
            class="max-h-40 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm outline-none placeholder:text-stone-400"
            @input="autoResize"
            @keydown="onKeydown"
        ></textarea>
        <span v-if="text.length > maxLength * 0.8" class="pb-2 text-xs text-stone-400">{{ text.length }}/{{ maxLength }}</span>
        <button
            v-if="loading"
            type="button"
            class="rounded-xl bg-stone-200 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-300"
            @click="emit('stop')"
        >Parar</button>
        <button
            v-else
            type="submit"
            :disabled="!text.trim()"
            class="rounded-xl bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40"
        >Enviar</button>
    </form>
</template>
