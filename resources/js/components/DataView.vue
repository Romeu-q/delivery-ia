<script setup>
import { computed } from 'vue';
import { formatField, label } from '../labels';

/**
 * Mostra, em tabelas, exatamente o que uma ferramenta trouxe do banco — para o gestor
 * conferir os números da resposta.
 */
const props = defineProps({
    data: { type: Object, required: true },
});

const HIDDEN = new Set(['definitions', 'method', 'campaign_created', 'not_sent', 'period', 'compared_with', 'previous_period', 'id']);

const isPlainObject = (v) => v && typeof v === 'object' && !Array.isArray(v);

const periodText = computed(() => {
    const p = props.data.period;
    const c = props.data.compared_with ?? props.data.previous_period;
    const fmt = (d) => new Date(d.replace(' ', 'T')).toLocaleDateString('pt-BR');
    let text = p ? `${p.label} (${fmt(p.start)} a ${fmt(p.end)})` : '';
    if (c) text += ` · comparado com ${fmt(c.start)} a ${fmt(c.end)}`;
    return text;
});

/** Separa os campos em: valores simples, tabelas de linhas e tabelas de indicadores. */
const sections = computed(() => {
    const scalars = [];
    const tables = [];
    for (const [key, value] of Object.entries(props.data)) {
        if (HIDDEN.has(key)) continue;
        if (Array.isArray(value) && value.length && isPlainObject(value[0])) {
            tables.push({ key, columns: Object.keys(value[0]), rows: value });
        } else if (Array.isArray(value)) {
            scalars.push({ key, value: value.length ? value.join(', ') : 'nenhum' });
        } else if (isPlainObject(value) && Object.values(value).every(isPlainObject)) {
            const columns = Object.keys(Object.values(value)[0] ?? {});
            tables.push({ key, columns, rows: Object.entries(value).map(([rowKey, row]) => ({ __row: rowKey, ...row })), metric: true });
        } else if (!isPlainObject(value)) {
            scalars.push({ key, value });
        }
    }
    return { scalars, tables };
});
</script>

<template>
    <div class="space-y-2 text-xs">
        <p v-if="periodText" class="text-stone-500">{{ periodText }}</p>

        <dl v-if="sections.scalars.length" class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
            <template v-for="s in sections.scalars" :key="s.key">
                <dt class="text-stone-500">{{ label(s.key) }}</dt>
                <dd class="font-medium text-stone-800">{{ formatField(s.key, s.value) }}</dd>
            </template>
        </dl>

        <div v-for="t in sections.tables" :key="t.key" class="overflow-x-auto">
            <div class="mb-1 font-medium text-stone-600">{{ label(t.key) }}</div>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-stone-500">
                        <th v-if="t.metric" class="py-1 pr-3 font-normal"></th>
                        <th v-for="c in t.columns" :key="c" class="py-1 pr-3 font-normal whitespace-nowrap">{{ label(c) }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in t.rows" :key="i" class="border-b border-stone-100 last:border-0">
                        <td v-if="t.metric" class="py-1 pr-3 text-stone-600 whitespace-nowrap">{{ label(row.__row) }}</td>
                        <td v-for="c in t.columns" :key="c" class="py-1 pr-3 tabular-nums text-stone-800 whitespace-nowrap">
                            {{ formatField(c, row[c], t.metric ? row.__row : '') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
