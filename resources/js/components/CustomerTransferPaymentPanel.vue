<template>
  <section v-if="isTransferOrder" class="rounded-xl border border-blue-200 bg-white p-5 shadow-sm" aria-live="polite">
    <h2 class="text-xl font-bold">Pago por transferencia</h2>
    <p class="mt-1 text-sm text-slate-600">Pedido #{{ order.id }} · S/ {{ order.total }} PEN</p>
    <div v-if="recoveringSubmission" class="py-6 text-sm">Recuperando tu presentación de pago…</div>
    <div v-else-if="submission" class="mt-4 rounded border p-4 text-sm" :class="submissionTone">
      <b>{{ submission.message }}</b><p>{{ submission.channel }} · S/ {{ submission.expected_amount }} {{ submission.currency }}</p>
      <p>Operación {{ submission.operation_number_masked }}</p><p v-if="submission.status === 'observed' && submission.reason" class="mt-2 font-medium">Motivo: {{ submission.reason }}</p><p v-if="submission.reservation_expires_at">Reserva vigente hasta {{ format(submission.reservation_expires_at) }}</p>
      <template v-if="submission.status === 'approved'">
        <p v-if="submission.validated_at" class="mt-2">Validado el {{ format(submission.validated_at) }}</p>
        <p class="mt-3 font-medium">Tu pedido continuará con preparación y entrega.</p>
      </template>
      <template v-if="manualReviewRequired">
        <div class="mt-4 rounded-lg border border-amber-300 bg-amber-100 p-4 text-amber-950" role="status">
          <p class="font-bold">Revisión manual requerida</p>
          <h3 class="mt-1 font-bold">Tu reserva venció, pero tu pago seguirá siendo revisado</h3>
          <p class="mt-2">Si ya realizaste la transferencia, no vuelvas a pagar ni crees otro pedido. Tesorería debe verificar el pago y confirmar la disponibilidad del producto. Conserva tu número de pedido y de operación.</p>
          <p class="mt-2">Pedido #{{ order.id }} · Operación {{ submission.operation_number_masked }} · S/ {{ submission.expected_amount }} {{ submission.currency }}</p>
          <a v-if="supportUrl" :href="supportUrl" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex font-bold underline">Contactar soporte por WhatsApp</a>
          <p v-else class="mt-3 font-medium">Comunícate con atención al cliente indicando el pedido #{{ order.id }}.</p>
        </div>
      </template>
      <template v-else-if="submission.status === 'observed'">
        <p class="mt-3 font-medium">Al reenviar, Tesorería volverá a revisar los datos.</p>
        <div v-if="loadingOptions" class="py-4">Cargando opciones para la corrección…</div>
        <div v-else-if="optionsError" class="mt-3 rounded bg-red-50 p-3 text-red-800">{{ optionsError }} <button type="button" class="ml-2 underline" @click="startCorrection">Reintentar</button></div>
        <template v-else-if="correctionFlow">
          <div v-if="!options.length" class="mt-3">No hay opciones disponibles para corregir la presentación.</div>
          <template v-else>
            <div class="mt-4 flex flex-wrap gap-2"><button v-for="item in options" :key="item.id" type="button" class="rounded border px-3 py-2" :class="selected?.id === item.id ? 'border-amber-600 bg-amber-100' : 'border-slate-300'" @click="select(item)">{{ labels[item.channel] || item.display_name }}</button></div>
            <div v-if="selected" class="mt-4 rounded bg-white/70 p-4"><p><b>{{ selected.display_name }}</b> · {{ selected.holder_name }}</p><template v-if="selected.channel === 'bank_transfer'"><p>{{ selected.bank_name }}</p><p>Cuenta: {{ selected.account_number }} <button type="button" class="underline" @click="copy(selected.account_number)">Copiar</button></p><p>CCI: {{ selected.cci }} <button type="button" class="underline" @click="copy(selected.cci)">Copiar</button></p></template><template v-else><p v-if="qrError" class="text-red-700">{{ qrError }} <button type="button" class="underline" @click="loadQr(selected)">Reintentar</button></p><p v-else-if="qrLoading">Cargando QR…</p><img v-else-if="qrUrl" :src="qrUrl" class="mt-3 max-h-64 max-w-full object-contain" :alt="`QR ${selected.display_name}`"></template></div>
            <form class="mt-5 grid gap-3 sm:grid-cols-2" @submit.prevent="submitCorrection"><label class="text-sm">Número de operación<input v-model="form.operation_number" type="text" class="mt-1 w-full rounded border p-2" required></label><label class="text-sm">Fecha y hora aproximada<input v-model="form.paid_at" type="datetime-local" class="mt-1 w-full rounded border p-2" required></label><label v-if="selected?.channel !== 'bank_transfer'" class="text-sm">Últimos cuatro dígitos<input v-model="form.origin_phone_last_four" inputmode="numeric" maxlength="4" class="mt-1 w-full rounded border p-2" required></label><label v-else class="text-sm">Banco de origen<input v-model="form.origin_bank" type="text" class="mt-1 w-full rounded border p-2" required></label><p v-for="(messages, field) in correctionErrors" :key="field" class="text-sm text-red-700">{{ messages[0] }}</p><p v-if="correctionConflict" class="text-sm text-red-800" role="alert">{{ correctionConflict }}</p><button class="rounded bg-amber-700 px-4 py-2 font-bold text-white disabled:opacity-50" :disabled="correctionSending || !selected">{{ correctionSending ? 'Reenviando…' : 'Reenviar corrección' }}</button></form>
          </template>
        </template>
      </template>
    </div>
    <div v-else-if="recoveryError" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">{{ recoveryError }} <button type="button" class="ml-2 underline" @click="recoverSubmission">Reintentar</button></div>
    <template v-else-if="newSubmissionFlow">
      <p class="mt-3 rounded bg-amber-50 p-3 text-sm text-amber-900">Realiza el pago exacto y registra una sola vez tu operación. Pendiente de validación por Tesorería.</p>
      <div v-if="loadingOptions" class="py-6 text-sm">Cargando instrucciones…</div>
      <div v-else-if="optionsError" class="py-3 text-sm text-red-700">{{ optionsError }} <button type="button" class="ml-2 underline" @click="recoverSubmission">Reintentar</button></div>
      <template v-else>
        <div v-if="!options.length" class="mt-4 text-sm">No hay canales configurados.</div>
        <div v-else class="mt-4 flex flex-wrap gap-2"><button v-for="item in options" :key="item.id" type="button" class="rounded border px-3 py-2" :class="selected?.id === item.id ? 'border-blue-600 bg-blue-50' : 'border-slate-300'" @click="select(item)">{{ labels[item.channel] || item.display_name }}</button></div>
        <div v-if="selected" class="mt-4 rounded bg-slate-50 p-4 text-sm">
          <p><b>{{ selected.display_name }}</b> · {{ selected.holder_name }}</p>
          <template v-if="selected.channel === 'bank_transfer'"><p>{{ selected.bank_name }}</p><p>Cuenta: {{ selected.account_number }} <button type="button" class="underline" @click="copy(selected.account_number)">Copiar</button></p><p>CCI: {{ selected.cci }} <button type="button" class="underline" @click="copy(selected.cci)">Copiar</button></p></template>
          <template v-else><p v-if="qrError" class="text-red-700">{{ qrError }} <button type="button" class="underline" @click="loadQr(selected)">Reintentar</button></p><p v-else-if="qrLoading">Cargando QR…</p><img v-else-if="qrUrl" :src="qrUrl" class="mt-3 max-h-64 max-w-full object-contain" :alt="`QR ${selected.display_name}`"></template>
        </div>
        <form class="mt-5 grid gap-3 sm:grid-cols-2" @submit.prevent="submit"><label class="text-sm">Número de operación<input v-model="form.operation_number" type="text" class="mt-1 w-full rounded border p-2" required></label><label class="text-sm">Fecha y hora aproximada<input v-model="form.paid_at" type="datetime-local" class="mt-1 w-full rounded border p-2" required></label><label v-if="selected?.channel !== 'bank_transfer'" class="text-sm">Últimos cuatro dígitos<input v-model="form.origin_phone_last_four" inputmode="numeric" maxlength="4" class="mt-1 w-full rounded border p-2" required></label><label v-else class="text-sm">Banco de origen<input v-model="form.origin_bank" type="text" class="mt-1 w-full rounded border p-2" required></label><p v-for="(messages, field) in errors" :key="field" class="text-sm text-red-700">{{ messages[0] }}</p><button class="rounded bg-blue-600 px-4 py-2 font-bold text-white disabled:opacity-50" :disabled="sending || !selected">{{ sending ? 'Registrando…' : 'Registrar operación' }}</button></form>
      </template>
    </template>
    <p v-else class="mt-4 rounded bg-slate-50 p-3 text-sm text-slate-700">Este pedido ya no permite una nueva presentación de pago.</p>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import api from '@/api'
import { useToast } from 'primevue/usetoast'

const props = defineProps({ order: { type: Object, required: true } })
const toast = useToast()
const options = ref([])
const selected = ref(null)
const submission = ref(null)
const newSubmissionFlow = ref(false)
const correctionFlow = ref(false)
const recoveringSubmission = ref(false)
const recoveryError = ref('')
const loadingOptions = ref(false)
const optionsError = ref('')
const optionsErrorCode = ref('')
const sending = ref(false)
const correctionSending = ref(false)
const errors = ref({})
const correctionErrors = ref({})
const correctionConflict = ref('')
const qrUrl = ref('')
const qrLoading = ref(false)
const qrError = ref('')
const key = ref('')
const correctionKey = ref('')
const form = ref({ operation_number: '', paid_at: '', origin_phone_last_four: '', origin_bank: '' })
const labels = { yape: 'Yape', plin: 'Plin', bank_transfer: 'Transferencia bancaria' }
let disposed = false
let recoveryRequestId = 0
let qrRequestId = 0
let recoveryController = null
let qrController = null

const isTransferOrder = computed(() => props.order?.payment_method === 'transferencia')
const manualReviewRequired = computed(() => submission.value?.status === 'observed' && optionsErrorCode.value === 'reservation_expired')
const supportPhone = String(import.meta.env.VITE_WHATSAPP_PHONE || '').replace(/\D+/g, '')
const supportUrl = computed(() => {
  if (!supportPhone || !manualReviewRequired.value) return ''
  const message = `Hola, solicito revisión manual del pedido #${props.order?.id}. Operación ${submission.value?.operation_number_masked || '—'}.`
  return `https://wa.me/${supportPhone}?text=${encodeURIComponent(message)}`
})
const submissionTone = computed(() => submission.value?.status === 'observed'
  ? 'border-amber-200 bg-amber-50 text-amber-950'
  : submission.value?.status === 'rejected'
    ? 'border-red-200 bg-red-50 text-red-950'
    : 'border-emerald-200 bg-emerald-50 text-emerald-950')
const format = value => new Date(value).toLocaleString('es-PE')
const newKey = () => crypto.randomUUID?.() || `${Date.now()}-${Math.random()}`
const isCurrentRecovery = (orderId, requestId) => !disposed && String(props.order?.id) === String(orderId) && recoveryRequestId === requestId

function revokeQr() {
  qrRequestId += 1
  qrController?.abort()
  qrController = null
  if (qrUrl.value) URL.revokeObjectURL(qrUrl.value)
  qrUrl.value = ''
  qrLoading.value = false
}

function resetForOrder() {
  recoveryController?.abort()
  recoveryController = null
  revokeQr()
  options.value = []
  selected.value = null
  submission.value = null
  newSubmissionFlow.value = false
  correctionFlow.value = false
  recoveringSubmission.value = false
  recoveryError.value = ''
  loadingOptions.value = false
  optionsError.value = ''
  optionsErrorCode.value = ''
  errors.value = {}
  correctionErrors.value = {}
  correctionConflict.value = ''
  sending.value = false
  correctionSending.value = false
  key.value = ''
  correctionKey.value = ''
  form.value = { operation_number: '', paid_at: '', origin_phone_last_four: '', origin_bank: '' }
}

async function recoverSubmission() {
  if (!isTransferOrder.value) return
  recoveryController?.abort()
  recoveryController = new AbortController()
  const orderId = props.order.id
  const requestId = ++recoveryRequestId
  recoveringSubmission.value = true
  recoveryError.value = ''
  optionsError.value = ''
  optionsErrorCode.value = ''
  try {
    const response = await api.get(`/orders/${orderId}/payment-submission`, { signal: recoveryController.signal })
    if (!isCurrentRecovery(orderId, requestId)) return
    submission.value = response.data.data
    newSubmissionFlow.value = false
    if (submission.value.status === 'observed') {
      await startCorrection(orderId, requestId)
    } else {
      correctionFlow.value = false
      options.value = []
      selected.value = null
      revokeQr()
    }
  } catch (error) {
    if (!isCurrentRecovery(orderId, requestId) || error.code === 'ERR_CANCELED') return
    if (error.response?.status === 404 && error.response?.data?.code === 'payment_submission_not_found') {
      newSubmissionFlow.value = true
      await loadOptions(orderId, requestId)
      return
    }
    recoveryError.value = error.response?.data?.message || 'No se pudo recuperar la presentación de pago.'
    toast.add({ severity: 'error', summary: 'No se pudo recuperar el pago', detail: recoveryError.value, life: 4000 })
  } finally {
    if (isCurrentRecovery(orderId, requestId)) recoveringSubmission.value = false
  }
}

async function startCorrection() {
  if (submission.value?.status !== 'observed') return
  correctionFlow.value = true
  await loadOptions(props.order.id, recoveryRequestId)
}

async function loadOptions(orderId, requestId) {
  if (!isCurrentRecovery(orderId, requestId)) return
  loadingOptions.value = true
  optionsError.value = ''
  optionsErrorCode.value = ''
  try {
    const response = await api.get(`/orders/${orderId}/payment-options`, { signal: recoveryController?.signal })
    if (!isCurrentRecovery(orderId, requestId)) return
    options.value = response.data.data || []
    const preferred = options.value.find(item => item.id === selected.value?.id)
      || options.value.find(item => item.channel === submission.value?.channel)
    if (preferred) await select(preferred)
  } catch (error) {
    if (!isCurrentRecovery(orderId, requestId) || error.code === 'ERR_CANCELED') return
    optionsError.value = error.response?.data?.message || 'No se pudieron cargar los canales.'
    optionsErrorCode.value = error.response?.data?.code || ''
    toast.add({ severity: 'error', summary: 'No se pudieron cargar los canales', detail: optionsError.value, life: 4000 })
  } finally {
    if (isCurrentRecovery(orderId, requestId)) loadingOptions.value = false
  }
}

async function select(item) {
  selected.value = item
  form.value.origin_phone_last_four = ''
  form.value.origin_bank = ''
  qrError.value = ''
  revokeQr()
  if (item.channel !== 'bank_transfer') await loadQr(item)
}

async function loadQr(item) {
  if (!item?.qr_url || selected.value?.id !== item.id) return
  qrController?.abort()
  qrController = new AbortController()
  const requestId = ++qrRequestId
  qrLoading.value = true
  qrError.value = ''
  try {
    const response = await api.get(item.qr_url, { responseType: 'blob', signal: qrController.signal })
    if (disposed || selected.value?.id !== item.id || qrRequestId !== requestId) return
    if (!/^image\/(png|jpeg|webp)/.test(response.headers['content-type'] || '')) throw new Error('invalid_qr')
    qrUrl.value = URL.createObjectURL(response.data)
  } catch (error) {
    if (disposed || qrRequestId !== requestId || error.code === 'ERR_CANCELED') return
    qrError.value = 'No se pudo cargar el QR.'
  } finally {
    if (!disposed && qrRequestId === requestId) qrLoading.value = false
  }
}

async function copy(value) {
  try {
    await navigator.clipboard?.writeText(value)
    toast.add({ severity: 'success', summary: 'Copiado', life: 2000 })
  } catch {
    toast.add({ severity: 'warn', summary: 'No se pudo copiar', life: 2500 })
  }
}

function submissionPayload() {
  const payload = { channel: selected.value.channel, receiving_account_id: selected.value.id, operation_number: form.value.operation_number, paid_at: new Date(form.value.paid_at).toISOString() }
  if (selected.value.channel === 'bank_transfer') payload.origin_bank = form.value.origin_bank
  else payload.origin_phone_last_four = form.value.origin_phone_last_four
  return payload
}

async function submit() {
  if (sending.value || !selected.value) return
  sending.value = true
  errors.value = {}
  key.value ||= newKey()
  try {
    submission.value = (await api.post(`/orders/${props.order.id}/payment-submission`, submissionPayload(), { headers: { 'Idempotency-Key': key.value } })).data.data
    revokeQr()
    toast.add({ severity: 'success', summary: 'Operación registrada', life: 3000 })
  } catch (error) {
    errors.value = error.response?.data?.errors || {}
    if (error.response?.status === 409) await recoverSubmission()
    toast.add({ severity: 'error', summary: 'No se pudo registrar', detail: error.response?.data?.message || 'Intenta nuevamente.', life: 4000 })
  } finally {
    sending.value = false
  }
}

async function submitCorrection() {
  if (correctionSending.value || !selected.value || submission.value?.status !== 'observed') return
  correctionSending.value = true
  correctionErrors.value = {}
  correctionConflict.value = ''
  correctionKey.value ||= newKey()
  try {
    submission.value = (await api.put(`/orders/${props.order.id}/payment-submission/correction`, submissionPayload(), { headers: { 'Idempotency-Key': correctionKey.value } })).data.data
    correctionFlow.value = false
    options.value = []
    selected.value = null
    form.value = { operation_number: '', paid_at: '', origin_phone_last_four: '', origin_bank: '' }
    correctionErrors.value = {}
    correctionKey.value = ''
    revokeQr()
    toast.add({ severity: 'success', summary: 'Corrección reenviada', detail: 'Tesorería volverá a revisar los datos.', life: 3500 })
  } catch (error) {
    correctionErrors.value = error.response?.data?.errors || {}
    if (error.response?.status === 409) {
      correctionConflict.value = error.response?.data?.message || 'La presentación cambió mientras la corregías.'
      await recoverSubmission()
      if (submission.value?.status === 'pending_review') {
        correctionConflict.value = ''
        toast.add({ severity: 'success', summary: 'Corrección recuperada', detail: 'Tesorería volverá a revisar los datos.', life: 3500 })
      }
    } else {
      const detail = error.response?.data?.message || 'No se pudo reenviar la corrección.'
      toast.add({ severity: 'error', summary: 'No se pudo reenviar', detail, life: 4000 })
    }
  } finally {
    correctionSending.value = false
  }
}

watch(() => props.order?.id, () => {
  resetForOrder()
  recoverSubmission()
}, { immediate: true })

watch(() => [form.value.operation_number, form.value.paid_at, form.value.origin_phone_last_four, form.value.origin_bank, selected.value?.id], () => {
  if (correctionConflict.value) {
    correctionConflict.value = ''
    correctionKey.value = ''
  }
})

onBeforeUnmount(() => {
  disposed = true
  recoveryController?.abort()
  revokeQr()
})
</script>
