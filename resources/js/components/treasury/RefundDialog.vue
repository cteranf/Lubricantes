<template>
  <dialog ref="dialog" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl backdrop:bg-slate-950/60" aria-labelledby="refund-action-title" aria-describedby="refund-action-description" @cancel.prevent="!busy && $emit('close')">
    <h2 id="refund-action-title" class="text-xl font-black">{{ title }}</h2>
    <p id="refund-action-description" class="mt-2 text-sm text-slate-600">{{ description }}</p>
    <slot />
  </dialog>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
defineProps({ title: String, description: String, busy: Boolean })
defineEmits(['close'])
const dialog = ref(null)
let previousFocus
onMounted(() => {
  previousFocus = document.activeElement
  dialog.value.showModal()
})
onBeforeUnmount(() => {
  dialog.value?.close()
  if (previousFocus?.isConnected) previousFocus.focus()
  else document.getElementById('refund-detail-title')?.focus()
})
</script>
