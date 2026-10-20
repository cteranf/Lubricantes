<template>
  <section class="space-y-5" aria-labelledby="refunds-title">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div><h2 id="refunds-title" class="text-2xl font-black">Reembolsos</h2><p class="text-sm text-slate-600">Gestión manual de devoluciones. Fechas por creación del pedido.</p></div>
      <button type="button" class="button" :disabled="s.commandLoading || s.readbackPending || s.listLoading || s.detailStatus === 'loading'" @click="manager.update">Actualizar</button>
    </header>
    <div aria-live="polite" class="space-y-2">
      <p v-if="s.notice" class="rounded-xl bg-emerald-50 p-3 text-emerald-900">{{ s.notice }}</p>
      <p v-if="s.refreshWarning" class="rounded-xl bg-amber-50 p-3 text-amber-900">{{ s.refreshWarning }}</p>
    </div>
    <form class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="manager.apply">
      <label class="filter">Pedido<input v-model="s.filters.order" class="field" placeholder="Número o #pedido" /></label>
      <label class="filter">Medio<select v-model="s.filters.payment_method" class="field"><option value="">Todos</option><option v-for="(label, value) in methods" :key="value" :value="value">{{ label }}</option></select></label>
      <label class="filter">Estado<select v-model="s.filters.refund_status" class="field"><option value="">Todos</option><option value="not_started">Sin iniciar</option><option value="pending">Pendiente</option><option value="completed">Completado</option><option value="manual_review">Revisión manual</option></select></label>
      <label class="filter">Por página<select v-model="s.filters.per_page" class="field"><option :value="5">5</option><option :value="10">10</option><option :value="20">20</option></select></label>
      <label class="filter">Creado desde<input v-model="s.filters.date_from" type="date" class="field" /></label>
      <label class="filter">Creado hasta<input v-model="s.filters.date_to" type="date" class="field" /></label>
      <div class="flex items-end gap-2"><button type="submit" class="button primary" :disabled="s.commandLoading">Aplicar</button><button type="button" class="button" :disabled="s.commandLoading" @click="manager.clear">Limpiar</button></div>
    </form>
    <p v-if="s.listError" role="alert" class="rounded-xl bg-red-50 p-4 text-red-900">{{ s.listError }}</p>
    <section class="rounded-2xl bg-white shadow-sm" :aria-busy="s.listLoading">
      <p v-if="s.listLoading" role="status" class="p-6">Cargando reembolsos…</p>
      <template v-else-if="s.rows.length">
        <div class="space-y-3 p-3 md:hidden">
          <article v-for="row in s.rows" :key="row.order_id" class="space-y-2 rounded-xl border p-4">
            <h3 class="font-black">{{ row.order_number }}</h3><p>{{ refundLabel(row) }}</p>
            <p>{{ method(row.original_payment_method) }} · {{ refundMoney(row.amount, row.currency) }}</p><p class="text-sm text-slate-500">{{ date(row.relevant_at) }}</p>
            <button type="button" class="button" :disabled="locked" :aria-label="`Ver reembolso de ${row.order_number}`" @click="select(row)">Ver detalle</button>
          </article>
        </div>
        <div class="hidden overflow-x-auto md:block"><table class="w-full min-w-[760px] text-left text-sm"><thead class="bg-slate-100"><tr><th v-for="heading in ['Pedido', 'Medio', 'Importe', 'Estado', 'Creado', 'Detalle']" :key="heading" scope="col" class="p-4">{{ heading }}</th></tr></thead><tbody><tr v-for="row in s.rows" :key="row.order_id" class="border-t"><td class="p-4 font-bold">{{ row.order_number }}</td><td class="p-4">{{ method(row.original_payment_method) }}</td><td class="p-4">{{ refundMoney(row.amount, row.currency) }}</td><td class="p-4">{{ refundLabel(row) }}</td><td class="p-4">{{ date(row.relevant_at) }}</td><td class="p-4"><button type="button" class="button" :disabled="locked" :aria-label="`Ver reembolso de ${row.order_number}`" @click="select(row)">Ver detalle</button></td></tr></tbody></table></div>
      </template>
      <AdminEmptyState v-else-if="!s.listError" title="No hay reembolsos en esta página" description="Aplica otros filtros o actualiza la lectura." />
      <p class="px-4 py-2 text-sm text-slate-600" aria-live="polite">{{ summary }}</p>
      <AdminPagination :page="s.meta.current_page || 1" :last-page="s.meta.last_page || 1" :summary="summary" :loading="s.listLoading || s.commandLoading || s.readbackPending" @change="manager.load" />
    </section>

    <section v-if="s.selectedId !== null" class="space-y-4 rounded-2xl bg-white p-5 shadow-sm" aria-labelledby="refund-detail-title" :aria-busy="s.detailStatus === 'loading'">
      <header class="flex items-center justify-between gap-3"><h2 id="refund-detail-title" ref="detailTitle" tabindex="-1" class="text-xl font-black">Detalle de reembolso</h2><button type="button" class="button" :disabled="locked" @click="manager.closeDetail">Cerrar detalle</button></header>
      <p v-if="s.detailStatus === 'loading'" role="status">Cargando detalle…</p>
      <p v-else-if="s.detailStatus === 'missing'" role="status">El recurso ya no está disponible.</p>
      <p v-else-if="s.detailStatus === 'conflict' || s.detailStatus === 'error'" role="alert">{{ s.detailError }}</p>
      <template v-else-if="s.detailStatus === 'loaded' && s.detail">
        <p class="font-bold">{{ refundLabel(s.detail) }}</p>
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div><dt class="text-slate-500">Pedido</dt><dd>{{ s.detail.order_number }}</dd></div>
          <div><dt class="text-slate-500">Medio</dt><dd>{{ method(s.detail.original_payment_method) }}</dd></div>
          <div><dt class="text-slate-500">Importe y moneda</dt><dd>{{ refundMoney(s.detail.amount, s.detail.currency) }}</dd></div>
          <div><dt class="text-slate-500">Creado</dt><dd>{{ date(s.detail.relevant_at) }}</dd></div>
          <div v-if="s.detail.refund"><dt class="text-slate-500">Devolución</dt><dd>{{ ({ pending: 'Pendiente', completed: 'Completada' })[s.detail.refund.status] || 'Sin acciones disponibles' }}</dd></div>
          <div v-if="s.detail.refund?.completed_at"><dt class="text-slate-500">Completada</dt><dd>{{ date(s.detail.refund.completed_at) }}</dd></div>
        </dl>
        <p v-if="s.detail.requires_manual_review === true" class="rounded-xl bg-amber-50 p-4 text-amber-900">Requiere revisión manual. No hay acciones financieras disponibles.</p>
        <div class="flex flex-wrap gap-3">
          <button v-if="manager.can('pending')" type="button" class="button primary" @click="manager.openAction('pending')">Marcar reembolso pendiente</button>
          <button v-if="manager.can('complete')" type="button" class="button primary" @click="manager.openAction('complete')">Completar reembolso</button>
        </div>
      </template>
    </section>

    <RefundDialog v-if="s.actionMode === 'pending'" title="Marcar reembolso pendiente" :description="`Confirma la solicitud para ${s.detail?.order_number || 'el pedido seleccionado'}.`" :busy="s.commandLoading" @close="manager.closeAction">
      <form class="mt-4 space-y-4" @submit.prevent="manager.submit">
        <label class="filter" for="refund-reason">Motivo obligatorio</label>
        <textarea id="refund-reason" v-model="s.reason" class="field" rows="4" required autofocus :disabled="s.commandLoading" aria-describedby="refund-reason-limit refund-reason-error" />
        <p id="refund-reason-limit" class="text-sm text-slate-600">{{ reasonCount }} caracteres. Máximo 1000 sin espacios exteriores; el servidor valida el texto.</p>
        <p id="refund-reason-error" class="text-sm text-red-800">{{ s.fieldError }}</p>
        <label class="flex gap-2 text-sm"><input v-model="s.confirmed" type="checkbox" required :disabled="s.commandLoading" />Confirmo que deseo marcar la devolución como pendiente.</label>
        <p v-if="s.commandError" role="alert">{{ s.commandError }}</p>
        <button v-if="s.blocked" type="button" class="button" :disabled="s.commandLoading || s.detailStatus === 'loading'" @click="manager.refreshDetail">Actualizar estado</button>
        <footer class="flex flex-wrap justify-end gap-3"><button type="button" class="button" :disabled="s.commandLoading" @click="manager.closeAction">Cancelar</button><button type="submit" class="button primary" :disabled="!s.confirmed || !manager.can('pending')">{{ s.commandLoading ? 'Enviando…' : 'Confirmar devolución pendiente' }}</button></footer>
      </form>
    </RefundDialog>
    <RefundDialog v-if="s.actionMode === 'complete'" title="Completar reembolso" :description="`Registra la devolución realizada para ${s.detail?.order_number || 'el pedido seleccionado'}.`" :busy="s.commandLoading" @close="manager.closeAction">
      <form class="mt-4 space-y-4" @submit.prevent="manager.submit">
        <label class="filter" for="refund-reference">Referencia externa obligatoria</label>
        <input id="refund-reference" v-model="s.external_reference" class="field" required autofocus autocomplete="off" spellcheck="false" :disabled="s.commandLoading" aria-describedby="refund-reference-limit refund-reference-error" />
        <p id="refund-reference-limit" class="text-sm text-slate-600">{{ referenceBytes }} bytes ingresados. Máximo 255 bytes sin espacios exteriores, una sola línea y sin controles.</p>
        <p id="refund-reference-error" class="text-sm text-red-800">{{ s.fieldError }}</p>
        <label class="flex gap-2 text-sm"><input v-model="s.confirmed" type="checkbox" required :disabled="s.commandLoading" />Confirmo que Tesorería realizó la devolución por el medio correspondiente.</label>
        <p v-if="s.commandError" role="alert">{{ s.commandError }}</p>
        <button v-if="s.blocked" type="button" class="button" :disabled="s.commandLoading || s.detailStatus === 'loading'" @click="manager.refreshDetail">Actualizar estado</button>
        <footer class="flex flex-wrap justify-end gap-3"><button type="button" class="button" :disabled="s.commandLoading" @click="manager.closeAction">Cancelar</button><button type="submit" class="button primary" :disabled="!s.confirmed || !manager.can('complete')">{{ s.commandLoading ? 'Enviando…' : 'Confirmar reembolso completado' }}</button></footer>
      </form>
    </RefundDialog>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import api from '@/api'
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue'
import AdminPagination from '@/components/admin/AdminPagination.vue'
import RefundDialog from './RefundDialog.vue'
import { createRefundManager, refundLabel, refundMoney } from '@/treasury/refundManager.mjs'

const emit = defineEmits(['busy'])
const manager = createRefundManager(api, reactive)
const s = manager.state
const detailTitle = ref(null)
const methods = { transferencia: 'Transferencia', card: 'Mercado Pago / tarjeta', contra_entrega: 'Contraentrega', pago_en_sede: 'Pago en sede' }
const method = value => methods[value] || '—'
const date = value => value && !Number.isNaN(new Date(value).getTime()) ? new Intl.DateTimeFormat('es-PE', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Lima' }).format(new Date(value)) : '—'
const locked = computed(() => s.commandLoading || !!s.actionMode)
const summary = computed(() => s.meta.total == null ? 'Sin lectura disponible' : `${s.meta.total} pedidos · Página ${s.meta.current_page} · ${s.meta.per_page} por página`)
const reasonCount = computed(() => Array.from(s.reason).length)
const referenceBytes = computed(() => new TextEncoder().encode(s.external_reference).length)
watch(locked, value => emit('busy', value))
async function select(row) {
  const loaded = await manager.openDetail(row)
  if (loaded && s.selectedId === row.order_id) { await nextTick(); detailTitle.value?.focus() }
}
onMounted(() => manager.load())
onBeforeUnmount(() => manager.dispose())
</script>

<style scoped>
.filter { display: flex; flex-direction: column; gap: .35rem; font-size: .875rem; font-weight: 700; color: #334155; }
.field { width: 100%; border: 1px solid #cbd5e1; border-radius: .5rem; padding: .6rem; font-weight: 400; }
.button { border: 1px solid #cbd5e1; border-radius: .5rem; padding: .6rem .9rem; font-size: .875rem; font-weight: 700; }
.primary { background: #1d4ed8; border-color: #1d4ed8; color: white; }
.button:disabled { opacity: .5; cursor: not-allowed; }
.button:focus-visible, .field:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
</style>
