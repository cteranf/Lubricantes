<template>
    <div class="relative h-64 w-full">
        <canvas ref="chartCanvas"></canvas>
    </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import Chart from 'chart.js/auto';

const props = defineProps({
    type: { type: String, default: 'line' },
    data: { type: Object, required: true },
    options: { type: Object, default: () => ({}) }
});

const chartCanvas = ref(null);
let chartInstance = null;

const renderChart = () => {
    if (chartInstance) chartInstance.destroy();
    
    chartInstance = new Chart(chartCanvas.value, {
        type: props.type,
        data: props.data,
        options: {
            maintainAspectRatio: false,
            responsive: true,
            ...props.options
        }
    });
};

onMounted(() => renderChart());
watch(() => props.data, () => renderChart(), { deep: true });
</script>
