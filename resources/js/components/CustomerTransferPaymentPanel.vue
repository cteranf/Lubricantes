<template>
  <div v-if="isTransferOrder" class="mt-5 border-t border-slate-100 pt-5 text-sm" aria-live="polite">
    <div v-if="recoveringSubmission" class="py-6 text-sm">Recuperando tu presentación de pago…</div>
    <div v-else-if="submission && !newSubmissionFlow" class="mt-4 rounded border p-4 text-sm" :class="submissionTone">
      <section v-if="submissions.length" class="mb-4 space-y-2" aria-label="Abonos registrados">
        <h3 class="font-bold">Abonos del pedido</h3>
        <p class="text-xs text-slate-700">Total {{ money(accounting.order_total) }} · Aprobado {{ money(accounting.approved_amount) }} · En revisión {{ money(accounting.in_review_amount) }} · Observado {{ money(accounting.observed_amount) }} · Saldo {{ money(accounting.remaining_amount) }} · Operaciones registradas {{ accounting.operations_used }} de {{ accounting.max_operations }}<template v-if="Number(accounting.remaining_amount) > 0"> · Operaciones disponibles {{ accounting.slots_left }}</template></p>
        <p v-if="accounting.payment_complete_in_review" class="rounded bg-blue-50 p-3 font-semibold text-blue-900" role="status">Importe completo en revisión. No registres otro abono mientras Tesorería termina de validar.</p>
        <div v-if="rejectedNeedsReview" class="rounded border border-amber-300 bg-amber-50 p-3 text-amber-950" role="status"><p class="font-bold">No realices otra transferencia todavía.</p><p>Hay un abono que requiere conciliación de Tesorería. Solicita una revisión antes de volver a pagar.</p><p v-if="accounting.reported_unresolved_amount" class="mt-1">Importe reportado pendiente de revisión: {{ money(accounting.reported_unresolved_amount) }}.</p><a v-if="supportUrl" :href="supportUrl" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex font-bold underline">Solicitar revisión por WhatsApp</a><p v-else class="mt-2">Comunícate con atención al cliente indicando el pedido #{{ order.id }}.</p></div>
        <article v-for="item in submissions" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 rounded border bg-white p-3"><span>{{ labels[item.channel] || 'Transferencia' }} · {{ item.amount === null ? 'Importe histórico no registrado' : money(item.amount) }}</span><span class="font-semibold">{{ statusLabel(item.status) }}</span><button v-if="item.status === 'observed'" type="button" class="font-bold text-amber-800 underline" @click="startCorrection(item)">Corregir este abono</button></article>
      </section>
      <b v-if="submission.status !== 'approved'">{{ submission.message }}</b>
      <div v-if="rejectedNeedsReview" class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 text-amber-950" role="status"><p class="font-bold">No realices otra transferencia todavía.</p><p>Hay un abono que requiere conciliación de Tesorería. Solicita una revisión antes de volver a pagar.</p><p v-if="accounting.reported_unresolved_amount" class="mt-1">Importe reportado pendiente de revisión: {{ money(accounting.reported_unresolved_amount) }}.</p><a v-if="supportUrl" :href="supportUrl" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex font-bold underline">Solicitar revisión por WhatsApp</a><p v-else class="mt-2">Comunícate con atención al cliente indicando el pedido #{{ order.id }}.</p></div>
      <p v-if="submission.channel !== 'bank_transfer'">Canal: {{ labels[submission.channel] || 'Pago digital' }}</p>
      <p>Operación {{ submission.operation_number_masked }}</p><p v-if="submission.status === 'observed' && submission.reason" class="mt-2 font-medium">Motivo: {{ submission.reason }}</p><p v-if="submission.reservation_status === 'active' && submission.reservation_expires_at">Reserva vigente hasta {{ format(submission.reservation_expires_at) }}</p><p v-else-if="submission.reservation_status === 'consumed'">Stock confirmado para tu pedido</p><p v-else-if="submission.reservation_status === 'expired'">La reserva de stock venció</p><p v-else-if="submission.reservation_status === 'released'">La reserva de stock fue liberada</p><p v-else-if="submission.reservation_status === 'mixed'">El stock del pedido se encuentra en actualización</p>
      <template v-if="submission.status === 'approved'">
        <p v-if="submission.validated_at" class="mt-2">Validado por Tesorería.</p>
      </template>
      <template v-if="manualReviewRequired">
        <div class="mt-4 rounded-lg border border-amber-300 bg-amber-100 p-4 text-amber-950" role="status">
          <p class="font-bold">Revisión manual requerida</p>
          <h3 class="mt-1 font-bold">Tu reserva venció, pero tu pago seguirá siendo revisado</h3>
          <p class="mt-2">Si ya realizaste la transferencia, no vuelvas a pagar ni crees otro pedido. Tesorería debe verificar el pago y confirmar la disponibilidad del producto. Conserva tu número de pedido y de operación.</p>
          <p class="mt-2">Pedido #{{ order.id }} · Operación {{ submission.operation_number_masked }}<template v-if="props.showExpectedAmount"> · S/ {{ submission.expected_amount }} {{ submission.currency }}</template></p>
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
            <div v-if="options.length > 1" class="mt-4 flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1" role="tablist" aria-label="Alternativas de pago">
              <button v-for="(item, index) in options" :id="`payment-option-tab-${item.id}`" :key="item.id" type="button" role="tab" :aria-selected="selected?.id === item.id" aria-controls="payment-option-panel" :tabindex="selected?.id === item.id ? 0 : -1" class="min-h-10 shrink-0 rounded-lg px-3 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" :class="selected?.id === item.id ? 'bg-white text-blue-900 shadow-sm' : 'text-slate-600 hover:text-slate-950'" @click="select(item)" @keydown="selectOptionFromKeyboard($event, index)">{{ labels[item.channel] || item.display_name }}</button>
            </div>
            <div v-else-if="selected" id="payment-option-single-label" class="mt-4 text-sm font-semibold text-blue-900">{{ labels[selected.channel] || selected.display_name }}</div>
            <div v-if="selected" id="payment-option-panel" class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm" role="tabpanel" :aria-labelledby="options.length > 1 ? `payment-option-tab-${selected.id}` : 'payment-option-single-label'">
              <p class="font-bold text-slate-950">{{ selected.display_name }}<span v-if="selected.holder_name && selected.holder_name !== selected.display_name" class="font-normal text-slate-600"> · {{ selected.holder_name }}</span></p>
              <p v-if="selected.instructions" class="mt-2 text-slate-700">{{ selected.instructions }}</p>
              <template v-if="selected.channel === 'bank_transfer'"><p v-if="selected.bank_name" class="mt-2">{{ selected.bank_name }}</p><p v-if="selected.account_number" class="mt-2 break-all">Cuenta: <b>{{ selected.account_number }}</b> <button type="button" class="ml-1 min-h-9 rounded px-2 font-semibold text-blue-800 underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" @click="copy(selected.account_number)">Copiar</button></p><p v-if="selected.cci" class="mt-1 break-all">CCI: <b>{{ selected.cci }}</b> <button type="button" class="ml-1 min-h-9 rounded px-2 font-semibold text-blue-800 underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" @click="copy(selected.cci)">Copiar</button></p></template>
              <template v-else><p v-if="qrError" class="mt-2 text-red-700">{{ qrError }} <button type="button" class="underline" @click="loadQr(selected)">Reintentar</button></p><p v-else-if="qrLoading" class="mt-3 text-slate-600" role="status">Cargando QR…</p><img v-else-if="qrUrl" :src="qrUrl" class="mx-auto mt-3 max-h-64 max-w-full object-contain" :alt="`QR ${selected.display_name}`"></template>
            </div>
          <form v-if="selected" class="mt-5" @submit.prevent="submitCorrection">
            <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="correction-payment-details-title">
              <h3 id="correction-payment-details-title" class="mb-5 text-base font-bold text-slate-900">Datos del abono</h3>
              <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                <label for="correction-amount" class="block text-sm font-semibold text-slate-700">Importe del abono (S/) <span class="text-red-700" aria-hidden="true">*</span>
                  <input id="correction-amount" v-model="form.amount" type="number" min="0.01" step="0.01" inputmode="decimal" class="mt-2 h-11 w-full rounded-lg border border-slate-300 px-3 text-base font-normal focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100" required :aria-invalid="Boolean(fieldError(correctionErrors, 'amount'))" :aria-describedby="fieldError(correctionErrors, 'amount') ? 'correction-amount-help correction-amount-error' : 'correction-amount-help'">
                  <span id="correction-amount-help" class="mt-1 block text-xs font-normal text-slate-600">Ingresa el importe que pagaste. Es obligatorio.</span>
                  <span v-if="fieldError(correctionErrors, 'amount')" id="correction-amount-error" class="mt-1 block text-xs font-medium text-red-700" role="alert">{{ fieldError(correctionErrors, 'amount') }}</span>
                </label>
                <label for="correction-operation-number" class="block text-sm font-semibold text-slate-700">Número de operación <span class="text-red-700" aria-hidden="true">*</span>
                  <input id="correction-operation-number" v-model="form.operation_number" type="text" class="mt-2 h-11 w-full rounded-lg border border-slate-300 px-3 text-base font-normal focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100" required :aria-invalid="Boolean(fieldError(correctionErrors, 'operation_number'))" :aria-describedby="fieldError(correctionErrors, 'operation_number') ? 'correction-operation-help correction-operation-error' : 'correction-operation-help'">
                  <span id="correction-operation-help" class="mt-1 block text-xs font-normal text-slate-600">Escribe el número que identifica la operación. Es obligatorio.</span>
                  <span v-if="fieldError(correctionErrors, 'operation_number')" id="correction-operation-error" class="mt-1 block text-xs font-medium text-red-700" role="alert">{{ fieldError(correctionErrors, 'operation_number') }}</span>
                </label>
                <label for="correction-paid-at" class="block text-sm font-semibold text-slate-700">Fecha y hora del pago <span class="text-red-700" aria-hidden="true">*</span>
                  <input id="correction-paid-at" v-model="form.paid_at" type="datetime-local" class="mt-2 h-11 w-full rounded-lg border border-slate-300 px-3 text-base font-normal focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100" required :aria-invalid="Boolean(fieldError(correctionErrors, 'paid_at'))" :aria-describedby="fieldError(correctionErrors, 'paid_at') ? 'correction-paid-at-help correction-paid-at-error' : 'correction-paid-at-help'">
                  <span id="correction-paid-at-help" class="mt-1 block text-xs font-normal text-slate-600">Indica cuándo realizaste el pago. Es obligatorio.</span>
                  <span v-if="fieldError(correctionErrors, 'paid_at')" id="correction-paid-at-error" class="mt-1 block text-xs font-medium text-red-700" role="alert">{{ fieldError(correctionErrors, 'paid_at') }}</span>
                </label>
                <label v-if="originField.key === 'origin_phone_last_four'" for="correction-origin-phone" class="block text-sm font-semibold text-slate-700">{{ originField.label }} <span class="text-red-700" aria-hidden="true">*</span>
                  <input id="correction-origin-phone" v-model="form.origin_phone_last_four" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" class="mt-2 h-11 w-full rounded-lg border border-slate-300 px-3 text-base font-normal focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100" required :aria-invalid="Boolean(fieldError(correctionErrors, originField.key))" :aria-describedby="fieldError(correctionErrors, originField.key) ? 'correction-origin-help correction-origin-error' : 'correction-origin-help'">
                  <span id="correction-origin-help" class="mt-1 block text-xs font-normal text-slate-600">{{ originField.help }}</span>
                  <span v-if="fieldError(correctionErrors, originField.key)" id="correction-origin-error" class="mt-1 block text-xs font-medium text-red-700" role="alert">{{ fieldError(correctionErrors, originField.key) }}</span>
                </label>
                <label v-else for="correction-origin-bank" class="block text-sm font-semibold text-slate-700">{{ originField.label }} <span class="text-red-700" aria-hidden="true">*</span>
                  <input id="correction-origin-bank" v-model="form.origin_bank" type="text" class="mt-2 h-11 w-full rounded-lg border border-slate-300 px-3 text-base font-normal focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100" required :aria-invalid="Boolean(fieldError(correctionErrors, originField.key))" :aria-describedby="fieldError(correctionErrors, originField.key) ? 'correction-origin-help correction-origin-error' : 'correction-origin-help'">
                  <span id="correction-origin-help" class="mt-1 block text-xs font-normal text-slate-600">{{ originField.help }}</span>
                  <span v-if="fieldError(correctionErrors, originField.key)" id="correction-origin-error" class="mt-1 block text-xs font-medium text-red-700" role="alert">{{ fieldError(correctionErrors, originField.key) }}</span>
                </label>
                <p v-if="fieldError(correctionErrors, 'payment_submission') || fieldError(correctionErrors, 'request')" class="text-sm text-red-800 sm:col-span-2" role="alert">{{ fieldError(correctionErrors, 'payment_submission') || fieldError(correctionErrors, 'request') }}</p>
                <p v-if="correctionConflict" class="text-sm text-red-800 sm:col-span-2" role="alert">{{ correctionConflict }}</p>
                <div class="sm:col-span-2 sm:flex sm:justify-end"><button class="min-h-11 w-full rounded-lg bg-amber-700 px-5 py-2 font-bold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800 disabled:opacity-50 sm:w-auto" :disabled="correctionSending">{{ correctionSending ? 'Reenviando…' : 'Reenviar corrección' }}</button></div>
              </div>
            </section>
          </form>
          </template>
        </template>
      </template>
    </div>
    <div v-else-if="recoveryError" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700">{{ recoveryError }} <button type="button" class="ml-2 underline" @click="recoverSubmission">Reintentar</button></div>
    <template v-else-if="newSubmissionFlow">
      <section v-if="submissions.length" class="mb-4 space-y-2" aria-label="Abonos registrados">
        <h3 class="font-bold">Abonos del pedido</h3>
        <p class="text-xs text-slate-700">Total {{ money(accounting.order_total) }} · Aprobado {{ money(accounting.approved_amount) }} · En revisión {{ money(accounting.in_review_amount) }} · Observado {{ money(accounting.observed_amount) }} · Saldo {{ money(accounting.remaining_amount) }} · Operaciones registradas {{ accounting.operations_used }} de {{ accounting.max_operations }}<template v-if="Number(accounting.remaining_amount) > 0"> · Operaciones disponibles {{ accounting.slots_left }}</template></p>
        <p v-if="accounting.payment_complete_in_review" class="rounded bg-blue-50 p-3 font-semibold text-blue-900" role="status">Importe completo en revisión. No registres otro abono mientras Tesorería termina de validar.</p>
        <div v-if="rejectedNeedsReview" class="rounded border border-amber-300 bg-amber-50 p-3 text-amber-950" role="status"><p class="font-bold">No realices otra transferencia todavía.</p><p>Hay un abono que requiere conciliación de Tesorería. Solicita una revisión antes de volver a pagar.</p></div>
        <article v-for="item in submissions" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 rounded border bg-white p-3"><span>{{ labels[item.channel] || 'Transferencia' }} · {{ item.amount === null ? 'Importe histórico no registrado' : money(item.amount) }}</span><span class="font-semibold">{{ statusLabel(item.status) }}</span><button v-if="item.status === 'observed'" type="button" class="font-bold text-amber-800 underline" @click="startCorrection(item)">Corregir este abono</button></article>
      </section>
      <p class="rounded bg-amber-50 p-3 text-sm text-amber-900">Registra un abono dentro del saldo disponible. El mínimo por operación es {{ money(accounting.minimum_amount) }}. Si queda un solo cupo, paga exactamente {{ money(accounting.required_next_amount || accounting.remaining_amount) }}.</p>
      <p class="mt-3 rounded bg-amber-50 p-3 text-sm text-amber-900">Realiza el pago exacto y registra una sola vez tu operación. Pendiente de validación por Tesorería.</p>
      <div v-if="loadingOptions" class="py-6 text-sm">Cargando instrucciones…</div>
      <div v-else-if="optionsError" class="py-3 text-sm text-red-700">{{ optionsError }} <button type="button" class="ml-2 underline" @click="recoverSubmission">Reintentar</button></div>
      <template v-else>
        <div v-if="!options.length" class="mt-4 text-sm">No hay canales configurados.</div>
        <div v-if="options.length > 1" class="mt-4 flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1" role="tablist" aria-label="Alternativas de pago">
          <button v-for="(item, index) in options" :id="`payment-option-tab-${item.id}`" :key="item.id" type="button" role="tab" :aria-selected="selected?.id === item.id" aria-controls="payment-option-panel" :tabindex="selected?.id === item.id ? 0 : -1" class="min-h-10 shrink-0 rounded-lg px-3 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" :class="selected?.id === item.id ? 'bg-white text-blue-900 shadow-sm' : 'text-slate-600 hover:text-slate-950'" @click="select(item)" @keydown="selectOptionFromKeyboard($event, index)">{{ labels[item.channel] || item.display_name }}</button>
        </div>
        <div v-else-if="selected" id="payment-option-single-label" class="mt-4 text-sm font-semibold text-blue-900">{{ labels[selected.channel] || selected.display_name }}</div>
        <div v-if="selected" id="payment-option-panel" class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm" role="tabpanel" :aria-labelledby="options.length > 1 ? `payment-option-tab-${selected.id}` : 'payment-option-single-label'">
          <p class="font-bold text-slate-950">{{ selected.display_name }}<span v-if="selected.holder_name && selected.holder_name !== selected.display_name" class="font-normal text-slate-600"> · {{ selected.holder_name }}</span></p>
          <p v-if="selected.instructions" class="mt-2 text-slate-700">{{ selected.instructions }}</p>
          <template v-if="selected.channel === 'bank_transfer'"><p v-if="selected.bank_name" class="mt-2">{{ selected.bank_name }}</p><p v-if="selected.account_number" class="mt-2 break-all">Cuenta: <b>{{ selected.account_number }}</b> <button type="button" class="ml-1 min-h-9 rounded px-2 font-semibold text-blue-800 underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" @click="copy(selected.account_number)">Copiar</button></p><p v-if="selected.cci" class="mt-1 break-all">CCI: <b>{{ selected.cci }}</b> <button type="button" class="ml-1 min-h-9 rounded px-2 font-semibold text-blue-800 underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" @click="copy(selected.cci)">Copiar</button></p></template>
          <template v-else><p v-if="qrError" class="mt-2 text-red-700">{{ qrError }} <button type="button" class="underline" @click="loadQr(selected)">Reintentar</button></p><p v-else-if="qrLoading" class="mt-3 text-slate-600" role="status">Cargando QR…</p><img v-else-if="qrUrl" :src="qrUrl" class="mx-auto mt-3 max-h-64 max-w-full object-contain" :alt="`QR ${selected.display_name}`"></template>
        </div>
        <form v-if="selected" class="mt-5" @submit.prevent="submit">
          <section class="rounded-xl border border-slate-200 bg-white p-5 sm:p-6" aria-labelledby="new-payment-details-title">
            <h3 id="new-payment-details-title" class="mb-5 text-base font-bold text-slate-900">Datos del abono</h3>
            <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
              <label for="new-amount" class="block text-sm font-semibold text-slate-700">Importe del abono (S/) <span class="text-red-700" aria-hidden="true">*</span>
                <input id="new-amount" v-model="form.amount" type="number" min="0.01" step="0.01" inputmode="decimal" class="mt-2 h-11 w-full rounded-lg border border-slate-300 px-3 text-base font-normal focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100" required :aria-invalid="Boolean(fieldError(errors, 'amount'))" :aria-describedby="fieldError(errors, 'amount') ? 'new-amount-help new-amount-error' : 'new-amount-help'">
                <span id="new-amount-help" class="mt-1 block text-xs font-normal text-slate-600">Ingresa el importe que pagaste. Es obligatorio.</span>
                <span v-if="fieldError(errors, 'amount')" id="new-amount-error" class="mt-1 block text-xs font-medium text-red-700" role="alert">{{ fieldError(errors, 'amount') }}</span>
              </label>
              <label for="new-operation-number" class="block text-sm font-semibold text-slate-700">Número de operación <span class="text-red-700" aria-hidden="true">*</span>
                <input id="new-operation-number" v-model="form.operation_number" type="text" class="mt-2 h-11 w-full rounded-lg border border-slate-300 px-3 text-base font-normal focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100" required :aria-invalid="Boolean(fieldError(errors, 'operation_number'))" :aria-describedby="fieldError(errors, 'operation_number') ? 'new-operation-help new-operation-error' : 'new-operation-help'">
                <span id="new-operation-help" class="mt-1 block text-xs font-normal text-slate-600">Escribe el número que identifica la operación. Es obligatorio.</span>
                <span v-if="fieldError(errors, 'operation_number')" id="new-operation-error" class="mt-1 block text-xs font-medium text-red-700" role="alert">{{ fieldError(errors, 'operation_number') }}</span>
              </label>
              <label for="new-paid-at" class="block text-sm font-semibold text-slate-700">Fecha y hora del pago <span class="text-red-700" aria-hidden="true">*</span>
                <input id="new-paid-at" v-model="form.paid_at" type="datetime-local" class="mt-2 h-11 w-full rounded-lg border border-slate-300 px-3 text-base font-normal focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100" required :aria-invalid="Boolean(fieldError(errors, 'paid_at'))" :aria-describedby="fieldError(errors, 'paid_at') ? 'new-paid-at-help new-paid-at-error' : 'new-paid-at-help'">
                <span id="new-paid-at-help" class="mt-1 block text-xs font-normal text-slate-600">Indica cuándo realizaste el pago. Es obligatorio.</span>
                <span v-if="fieldError(errors, 'paid_at')" id="new-paid-at-error" class="mt-1 block text-xs font-medium text-red-700" role="alert">{{ fieldError(errors, 'paid_at') }}</span>
              </label>
              <label v-if="originField.key === 'origin_phone_last_four'" for="new-origin-phone" class="block text-sm font-semibold text-slate-700">{{ originField.label }} <span class="text-red-700" aria-hidden="true">*</span>
                <input id="new-origin-phone" v-model="form.origin_phone_last_four" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" class="mt-2 h-11 w-full rounded-lg border border-slate-300 px-3 text-base font-normal focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100" required :aria-invalid="Boolean(fieldError(errors, originField.key))" :aria-describedby="fieldError(errors, originField.key) ? 'new-origin-help new-origin-error' : 'new-origin-help'">
                <span id="new-origin-help" class="mt-1 block text-xs font-normal text-slate-600">{{ originField.help }}</span>
                <span v-if="fieldError(errors, originField.key)" id="new-origin-error" class="mt-1 block text-xs font-medium text-red-700" role="alert">{{ fieldError(errors, originField.key) }}</span>
              </label>
              <label v-else for="new-origin-bank" class="block text-sm font-semibold text-slate-700">{{ originField.label }} <span class="text-red-700" aria-hidden="true">*</span>
                <input id="new-origin-bank" v-model="form.origin_bank" type="text" class="mt-2 h-11 w-full rounded-lg border border-slate-300 px-3 text-base font-normal focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100" required :aria-invalid="Boolean(fieldError(errors, originField.key))" :aria-describedby="fieldError(errors, originField.key) ? 'new-origin-help new-origin-error' : 'new-origin-help'">
                <span id="new-origin-help" class="mt-1 block text-xs font-normal text-slate-600">{{ originField.help }}</span>
                <span v-if="fieldError(errors, originField.key)" id="new-origin-error" class="mt-1 block text-xs font-medium text-red-700" role="alert">{{ fieldError(errors, originField.key) }}</span>
              </label>
              <p v-if="fieldError(errors, 'payment_submission') || fieldError(errors, 'request')" class="text-sm text-red-800 sm:col-span-2" role="alert">{{ fieldError(errors, 'payment_submission') || fieldError(errors, 'request') }}</p>
              <div class="sm:col-span-2 sm:flex sm:justify-end"><button class="min-h-11 w-full rounded-lg bg-blue-700 px-5 py-2 font-bold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-800 disabled:opacity-50 sm:w-auto" :disabled="sending || accounting.slots_left <= 0 || Number(accounting.remaining_amount) <= 0">{{ sending ? 'Registrando…' : 'Registrar abono' }}</button></div>
            </div>
          </section>
        </form>
      </template>
    </template>
    <p v-else class="mt-4 rounded bg-slate-50 p-3 text-sm text-slate-700">Este pedido ya no permite una nueva presentación de pago.</p>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import api from '@/api'
import { useToast } from 'primevue/usetoast'

const props = defineProps({
  order: { type: Object, required: true },
  showExpectedAmount: { type: Boolean, default: true },
})
const toast = useToast()
const options = ref([])
const selected = ref(null)
const submission = ref(null)
const submissions = ref([])
const accounting = ref({ order_total: String(props.order?.total || '0.00'), approved_amount: '0.00', in_review_amount: '0.00', observed_amount: '0.00', reported_rejected_amount: '0.00', reported_unresolved_amount: '0.00', requires_manual_reconciliation: false, remaining_amount: String(props.order?.total || '0.00'), minimum_amount: '0.00', max_operations: 0, operations_used: 0, slots_left: 0, required_next_amount: null, payment_complete_in_review: false })
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
const form = ref({ amount: '', operation_number: '', paid_at: '', origin_phone_last_four: '', origin_bank: '' })
const labels = { yape: 'Yape', plin: 'Plin', bank_transfer: 'Transferencia bancaria' }
let disposed = false
let recoveryRequestId = 0
let qrRequestId = 0
let recoveryController = null
let qrController = null

const isTransferOrder = computed(() => props.order?.payment_method === 'transferencia')
const manualReviewRequired = computed(() => submission.value?.status === 'observed' && optionsErrorCode.value === 'reservation_expired')
const rejectedNeedsReview = computed(() => submissions.value.some(item => ['rejected', 'manual_resolution_required'].includes(item.status)))
const supportPhone = String(import.meta.env.VITE_WHATSAPP_PHONE || '').replace(/\D+/g, '')
const supportUrl = computed(() => {
  if (!supportPhone || (!manualReviewRequired.value && !rejectedNeedsReview.value)) return ''
  const message = `Hola, solicito revisión manual del pedido #${props.order?.id}. Operación ${submission.value?.operation_number_masked || '—'}.`
  return `https://wa.me/${supportPhone}?text=${encodeURIComponent(message)}`
})
const submissionTone = computed(() => submission.value?.status === 'observed'
  ? 'border-amber-200 bg-amber-50 text-amber-950'
  : submission.value?.status === 'rejected'
    ? 'border-red-200 bg-red-50 text-red-950'
    : 'border-emerald-200 bg-emerald-50 text-emerald-950')
const format = value => new Date(value).toLocaleString('es-PE')
const money = value => `S/ ${Number(value || 0).toFixed(2)}`
const fieldError = (bag, field) => bag?.[field]?.[0] || ''
const originField = computed(() => selected.value?.channel === 'bank_transfer'
  ? { key: 'origin_bank', label: 'Banco desde el que transferiste', help: 'Escribe el nombre del banco de la cuenta desde la que transferiste. Es obligatorio.' }
  : { key: 'origin_phone_last_four', label: 'Últimos 4 dígitos del celular desde el que pagaste', help: 'Indica los últimos cuatro dígitos del celular del pagador. Es obligatorio.' })
const statusLabel = value => ({ pending_review: 'En revisión', observed: 'Observado', approved: 'Aprobado', rejected: 'Rechazado', manual_resolution_required: 'Revisión manual', expired: 'Vencido', canceled: 'Cancelado' }[value] || 'Registrado')
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
  submissions.value = []
  accounting.value = { order_total: String(props.order?.total || '0.00'), approved_amount: '0.00', in_review_amount: '0.00', observed_amount: '0.00', reported_rejected_amount: '0.00', reported_unresolved_amount: '0.00', requires_manual_reconciliation: false, remaining_amount: String(props.order?.total || '0.00'), minimum_amount: '0.00', max_operations: 0, operations_used: 0, slots_left: 0, required_next_amount: null, payment_complete_in_review: false }
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
  form.value = { amount: '', operation_number: '', paid_at: '', origin_phone_last_four: '', origin_bank: '' }
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
    const payload = response.data.data
    submissions.value = Array.isArray(payload.submissions) ? payload.submissions : [payload]
    accounting.value = payload.accounting || accounting.value
    submission.value = submissions.value[0] || null
    newSubmissionFlow.value = Number(accounting.value.remaining_amount) > 0 && Number(accounting.value.slots_left) > 0 && !accounting.value.requires_manual_reconciliation && !rejectedNeedsReview.value
    form.value.amount = accounting.value.required_next_amount || accounting.value.remaining_amount || ''
    if (newSubmissionFlow.value) {
      await loadOptions(orderId, requestId)
    } else if (submission.value?.status === 'observed') {
      await startCorrection(submission.value)
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

async function startCorrection(target = submission.value) {
  if (target?.status !== 'observed') return
  newSubmissionFlow.value = false
  submission.value = target
  form.value.amount = target.amount || accounting.value.remaining_amount
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
    const payload = response.data?.data
    options.value = Array.isArray(payload) ? payload : []
    if (response.data?.accounting) {
      accounting.value = response.data.accounting
      if (!correctionFlow.value) form.value.amount = accounting.value.required_next_amount || accounting.value.remaining_amount || ''
    }
    const preferred = options.value.find(item => item.id === selected.value?.id)
      || options.value.find(item => item.channel === submission.value?.channel)
      || options.value[0]
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

async function selectOptionFromKeyboard(event, currentIndex) {
  let nextIndex = currentIndex
  if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % options.value.length
  else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + options.value.length) % options.value.length
  else if (event.key === 'Home') nextIndex = 0
  else if (event.key === 'End') nextIndex = options.value.length - 1
  else return

  event.preventDefault()
  await select(options.value[nextIndex])
  await nextTick()
  document.getElementById(`payment-option-tab-${options.value[nextIndex]?.id}`)?.focus()
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
  const payload = { amount: String(form.value.amount), channel: selected.value.channel, receiving_account_id: selected.value.id, operation_number: form.value.operation_number, paid_at: new Date(form.value.paid_at).toISOString() }
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
    await api.post(`/orders/${props.order.id}/payment-submission`, submissionPayload(), { headers: { 'Idempotency-Key': key.value } })
    revokeQr()
    key.value = ''
    await recoverSubmission()
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
    await api.put(`/orders/${props.order.id}/payment-submission/correction`, { ...submissionPayload(), submission_id: submission.value.id }, { headers: { 'Idempotency-Key': correctionKey.value } })
    correctionFlow.value = false
    options.value = []
    selected.value = null
    form.value = { amount: '', operation_number: '', paid_at: '', origin_phone_last_four: '', origin_bank: '' }
    correctionErrors.value = {}
    correctionKey.value = ''
    revokeQr()
    toast.add({ severity: 'success', summary: 'Corrección reenviada', detail: 'Tesorería volverá a revisar los datos.', life: 3500 })
    await recoverSubmission()
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
