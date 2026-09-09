<template>
  <header class="flex flex-col gap-4 rounded-2xl bg-slate-900 p-5 text-white shadow-sm sm:p-6 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <p class="text-xs font-bold uppercase tracking-[.16em] text-blue-300">{{ eyebrow }}</p>
      <h1 :id="titleId" class="mt-1 text-3xl font-black tracking-tight">{{ title }}</h1>
      <p class="mt-2 max-w-2xl text-sm text-slate-300">{{ description }}</p>
    </div>
    <div class="shrink-0"><slot name="action" /></div>
  </header>
</template>
<script setup>
defineProps({ eyebrow: { type: String, required: true }, title: { type: String, required: true }, description: { type: String, required: true }, titleId: { type: String, required: true } });
</script>
