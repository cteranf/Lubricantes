<template><span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold" :class="toneClass"><i :class="icon" aria-hidden="true"></i>{{ label }}</span></template>
<script setup>
import { computed } from 'vue';
const props = defineProps({ active: { type: Boolean, required: true }, activeLabel: { type: String, default: 'Activo' }, inactiveLabel: { type: String, default: 'Inactivo' } });
const label = computed(() => props.active ? props.activeLabel : props.inactiveLabel);
const icon = computed(() => props.active ? 'pi pi-check-circle' : 'pi pi-ban');
const toneClass = computed(() => props.active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700');
</script>
