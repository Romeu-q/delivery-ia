<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    BarController, BarElement, CategoryScale, Chart, Legend, LinearScale,
    LineController, LineElement, PointElement, Tooltip, Filler,
} from 'chart.js';
import { formatValue } from '../format';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, LineController, LineElement, PointElement, Tooltip, Legend, Filler);

/** Gráfico gerado a partir dos dados do banco retornados pela ferramenta (não pela IA). */
const props = defineProps({
    chart: { type: Object, required: true },
});

const canvas = ref(null);
let instance = null;
const palette = ['#f2622e', '#a8a29e', '#0f766e', '#6366f1'];

function render() {
    instance?.destroy();
    const { type, labels, datasets, format } = props.chart;
    const comparison = datasets.length > 1;

    instance = new Chart(canvas.value, {
        type,
        data: {
            labels,
            datasets: datasets.map((d, i) => {
                // Em comparações, o período anterior fica neutro e o atual em destaque
                const color = comparison ? palette[(datasets.length - 1 - i) === 0 ? 0 : 1] : palette[0];
                return {
                    ...d,
                    backgroundColor: type === 'line' ? `${color}22` : color,
                    borderColor: color,
                    borderWidth: type === 'line' ? 2 : 0,
                    borderRadius: 4,
                    fill: type === 'line',
                    tension: 0.3,
                    pointRadius: labels.length > 40 ? 0 : 3,
                };
            }),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: comparison, labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${formatValue(ctx.parsed.y, format)}` } },
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 }, maxRotation: 45, autoSkip: true } },
                y: { beginAtZero: true, ticks: { font: { size: 11 }, callback: (v) => formatValue(v, format) } },
            },
        },
    });
}

onMounted(render);
watch(() => props.chart, render, { deep: true });
onBeforeUnmount(() => instance?.destroy());
</script>

<template>
    <figure class="rounded-lg border border-stone-200 bg-white p-3">
        <figcaption class="mb-2 text-xs font-medium text-stone-600">{{ chart.title }}</figcaption>
        <div class="relative h-56">
            <canvas ref="canvas" :aria-label="chart.title" role="img"></canvas>
        </div>
    </figure>
</template>
