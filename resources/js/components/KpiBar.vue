<script setup>
import { computed } from 'vue';
import { formatCurrency, formatNumber, formatPct } from '../format';

const props = defineProps({
    dashboard: { type: Object, default: null },
});
const emit = defineEmits(['ask']);

const cards = computed(() => {
    const k = props.dashboard?.kpis;
    if (!k) return [];
    return [
        { label: 'Faturamento', value: formatCurrency(k.revenue.current), change: k.revenue.change_pct },
        { label: 'Pedidos', value: formatNumber(k.orders.current), change: k.orders.change_pct },
        { label: 'Ticket médio', value: formatCurrency(k.avg_ticket.current), change: k.avg_ticket.change_pct },
        { label: 'Clientes', value: formatNumber(k.unique_customers.current), change: k.unique_customers.change_pct },
    ];
});
</script>

<template>
    <section v-if="dashboard" class="border-b border-stone-200 bg-white px-4 py-3 md:px-6">
        <div class="mb-2 flex items-baseline justify-between gap-2">
            <h2 class="text-xs font-medium uppercase tracking-wide text-stone-500">Este mês até agora</h2>
            <span class="text-xs text-stone-400">vs. mesmos dias do mês passado</span>
        </div>
        <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
            <div v-for="card in cards" :key="card.label" class="rounded-lg border border-stone-100 bg-stone-50 px-3 py-2">
                <div class="text-xs text-stone-500">{{ card.label }}</div>
                <div class="flex items-baseline gap-2">
                    <span class="text-lg font-semibold text-stone-900">{{ card.value }}</span>
                    <span
                        class="text-xs font-medium"
                        :class="card.change === null ? 'text-stone-400' : card.change >= 0 ? 'text-emerald-600' : 'text-red-600'"
                    >{{ formatPct(card.change) }}</span>
                </div>
            </div>
        </div>
        <div v-if="dashboard.alerts?.length" class="mt-2 flex flex-wrap gap-2">
            <button
                v-for="alert in dashboard.alerts"
                :key="alert.type"
                type="button"
                class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs text-amber-800 hover:bg-amber-100"
                @click="emit('ask', alert.question)"
            >
                ⚠ {{ alert.text }} — perguntar
            </button>
        </div>
    </section>
</template>
