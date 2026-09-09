<template><button type="button" class="admin-icon-button" :class="tone" :disabled="disabled" :aria-label="label" :data-tooltip="label"><i :class="icon" aria-hidden="true"></i><span class="sr-only">{{ label }}</span></button></template>
<script setup>defineProps({ icon: { type: String, required: true }, label: { type: String, required: true }, tone: { type: String, default: 'text-blue-700' }, disabled: Boolean });</script>
<style scoped>
.admin-icon-button { position: relative; display: inline-flex; width: 2.5rem; height: 2.5rem; align-items: center; justify-content: center; border-radius: .6rem; transition: background-color .15s ease, color .15s ease; }
.admin-icon-button:hover:not(:disabled) { background: rgb(241 245 249); }
.admin-icon-button:focus-visible { outline: 2px solid rgb(37 99 235); outline-offset: 2px; }
.admin-icon-button:disabled { cursor: not-allowed; opacity: .45; }
.admin-icon-button::after { content: attr(data-tooltip); pointer-events: none; position: absolute; z-index: 60; left: 50%; bottom: calc(100% + .5rem); transform: translateX(-50%); width: max-content; max-width: 14rem; border-radius: .4rem; background: rgb(15 23 42); color: white; padding: .35rem .5rem; font-size: .75rem; opacity: 0; visibility: hidden; transition: opacity .15s ease; }
.admin-icon-button:hover::after, .admin-icon-button:focus-visible::after { opacity: 1; visibility: visible; }
</style>
