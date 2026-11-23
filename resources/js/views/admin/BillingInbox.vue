<template>
  <AdminLayout>
    <main class="mx-auto max-w-7xl space-y-6" aria-labelledby="billing-title">
      <header class="rounded-2xl bg-slate-900 p-5 text-white shadow-sm sm:p-7">
        <p class="text-xs font-bold uppercase tracking-[.16em] text-blue-300">CONSULTA DE PEDIDOS</p>
        <h1 id="billing-title" class="mt-1 text-3xl font-black">Facturación</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-300">
          Revisa solicitudes del cliente y prepara documentos electrónicos. Las facturas solo se emiten en ambiente Beta; las boletas se generan localmente para incluirlas en un resumen simulado que no contacta SUNAT.
        </p>
      </header>

      <section class="space-y-4 rounded-2xl border border-amber-300 bg-amber-50 p-4 shadow-sm sm:p-5" aria-labelledby="receipt-summary-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div><p class="text-xs font-bold uppercase tracking-wide text-amber-800">BOLETAS · QA LOCAL</p><h2 id="receipt-summary-title" class="mt-1 text-xl font-black text-slate-900">Resumen diario de boletas</h2><p class="mt-1 max-w-3xl text-sm text-slate-700">Primero genera cada boleta firmada y numerada desde su detalle. Después selecciona documentos listos para incluirlos. La comunicación está simulada localmente: no contacta SUNAT ni representa una aceptación.</p></div>
          <Button label="Actualizar boletas" icon="pi pi-refresh" severity="secondary" outlined size="small" :disabled="summaryBusy" @click="refreshReceiptSummaries" />
        </div>
        <p v-if="summaryError" class="rounded-lg border border-red-200 bg-white p-3 text-sm text-red-800" role="alert">{{ summaryError }}</p>
        <p v-if="summarySetupReasons.length" class="rounded-lg border border-amber-200 bg-white p-3 text-sm text-amber-900" role="status"><b>Preparación no disponible:</b> {{ summarySetupReasons.map(blockingReasonLabel).join(' · ') }}</p>
        <div v-if="eligibleReceipts.length" class="overflow-x-auto rounded-xl border border-amber-200 bg-white">
          <table class="w-full min-w-[700px] text-left text-sm"><thead class="bg-white text-xs uppercase text-slate-500"><tr><th class="p-3">Seleccionar</th><th class="p-3">Pedido</th><th class="p-3">Documento firmado</th><th class="p-3 text-right">Total histórico</th><th class="p-3">Generado</th></tr></thead>
            <tbody><tr v-for="receipt in eligibleReceipts" :key="receipt.id" class="border-t border-slate-100"><td class="p-3"><input v-model="selectedReceiptIds" type="checkbox" :value="receipt.id" :aria-label="`Incluir boleta ${receipt.series}-${receipt.document_number} de ${receipt.order_number}`" :disabled="summaryBusy || !receipt.eligible || selectedReceiptIds.length >= 500 && !selectedReceiptIds.includes(receipt.id)" class="h-5 w-5 accent-blue-700" /></td><td class="p-3 font-semibold">{{ receipt.order_number }}<small v-if="!receipt.eligible" class="block font-normal text-amber-800">{{ (receipt.blocking_reasons || []).map(blockingReasonLabel).join(' · ') }}</small></td><td class="p-3">{{ receipt.series }}-{{ String(receipt.document_number).padStart(8, '0') }} · v{{ receipt.version }}</td><td class="p-3 text-right">S/ {{ money(receipt.total) }}</td><td class="p-3">{{ formatDate(receipt.prepared_at) }}</td></tr></tbody>
          </table>
        </div>
        <p v-else class="rounded-xl border border-amber-200 bg-white p-4 text-sm text-slate-700" role="status">{{ summaryLoading ? 'Buscando borradores…' : 'No hay borradores de boleta disponibles para seleccionar.' }}</p>
        <div class="flex flex-wrap items-center justify-between gap-3"><p class="text-sm text-slate-700">Seleccionadas: {{ selectedReceiptIds.length }} / 500</p><Button label="Incluir en resumen simulado" icon="pi pi-file-edit" :disabled="summaryBusy || !electronicEnabled || !summarySetupAvailable || selectedReceiptIds.length === 0" @click="confirmReceiptSummary" /></div>
        <p v-if="!electronicEnabled" class="text-sm text-amber-900" role="status">El módulo está deshabilitado. Puedes consultar resúmenes existentes, pero no preparar uno nuevo.</p>
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          <section class="rounded-xl border border-amber-200 bg-white p-3" aria-label="Resúmenes diarios existentes"><h3 class="font-bold">Resúmenes preparados</h3><ul v-if="receiptSummaries.length" class="mt-2 divide-y"> <li v-for="summary in receiptSummaries" :key="summary.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"><span><b>{{ summary.document_id }}</b><small class="block text-slate-500">{{ summary.document_count }} boletas · {{ summary.status_label }}</small><small class="block text-slate-500">Comunicación: {{ communicationLabel(summary.communication_status) }} · modo simulado local</small></span><Button label="Ver detalle" size="small" severity="secondary" outlined :disabled="summaryBusy" @click="openReceiptSummary(summary.id)" /></li></ul><p v-else class="mt-2 text-sm text-slate-600">No hay resúmenes locales.</p></section>
          <section v-if="selectedReceiptSummary" class="space-y-3 rounded-xl border border-amber-200 bg-white p-3" aria-label="Detalle del resumen diario"><div><h3 class="font-bold">{{ selectedReceiptSummary.document_id }}</h3><p class="text-sm text-slate-600">{{ selectedReceiptSummary.status_label }} · modo de ejecución simulado localmente</p></div><dl class="grid gap-2 rounded bg-amber-50 p-3 text-sm sm:grid-cols-2"><div><dt class="font-semibold">Estado de comunicación</dt><dd>{{ selectedReceiptSummary.communication_status_label }}</dd></div><div><dt class="font-semibold">Ticket externo SUNAT</dt><dd>{{ selectedReceiptSummary.external_ticket || 'No existe; no se llamó a SUNAT' }}</dd></div></dl><p v-if="selectedReceiptSummary.local_simulation_id" class="break-all rounded bg-slate-50 p-2 font-mono text-xs">Identificador LOCAL-SIM (solo local): {{ selectedReceiptSummary.local_simulation_id }}</p><p v-if="selectedReceiptSummary.result_message" class="text-sm" role="status">{{ selectedReceiptSummary.result_message }}</p><ul class="divide-y rounded border"><li v-for="item in selectedReceiptSummary.items || []" :key="item.line_number" class="flex justify-between gap-2 p-2 text-sm"><span>Línea {{ item.line_number }} · {{ item.document?.order_number }} · {{ item.document?.series }}-{{ String(item.document?.document_number || '').padStart(8, '0') }}</span><span>{{ summaryItemLabel(item.result_status) }}</span></li></ul><div class="flex flex-wrap gap-2"><Button v-if="canQueryReceiptSummary" label="Consultar estado simulado" icon="pi pi-refresh" size="small" severity="secondary" outlined :disabled="summaryBusy || !electronicEnabled" @click="queryReceiptSummary" /><Button v-if="selectedReceiptSummary.files?.xml" label="XML simulado" icon="pi pi-download" size="small" severity="secondary" outlined :disabled="summaryBusy" @click="downloadReceiptSummary('xml')" /><Button v-if="selectedReceiptSummary.files?.archive" label="ZIP simulado" icon="pi pi-download" size="small" severity="secondary" outlined :disabled="summaryBusy" @click="downloadReceiptSummary('archive')" /></div><p class="text-xs text-slate-500">No hay CDR auténtica en este modo simulado; no se fabrica ningún resultado descargable.</p></section>
        </div>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" aria-label="Filtros de facturación">
        <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6" @submit.prevent="load(1)">
          <label class="text-sm font-semibold text-slate-700 lg:col-span-2">
            Número de pedido o cliente
            <input v-model.trim="filters.search" class="field mt-1" placeholder="Número o nombre" />
          </label>
          <label class="text-sm font-semibold text-slate-700">
            Tipo solicitado
            <select v-model="filters.receipt_type" class="field mt-1"><option value="">Todos</option><option value="boleta">Boleta</option><option value="factura">Factura</option></select>
          </label>
          <label class="text-sm font-semibold text-slate-700">Desde<input v-model="filters.date_from" type="date" class="field mt-1" /></label>
          <label class="text-sm font-semibold text-slate-700">Hasta<input v-model="filters.date_to" type="date" class="field mt-1" /></label>
          <label class="flex items-end gap-2 pb-2 text-sm font-semibold text-slate-700"><input v-model="filters.missing_data" type="checkbox" class="h-4 w-4 accent-blue-700" />Datos faltantes</label>
          <div class="flex gap-2 sm:col-span-2 lg:col-span-6">
            <Button type="submit" label="Aplicar filtros" icon="pi pi-filter" :disabled="loading" />
            <Button type="button" label="Limpiar" severity="secondary" outlined :disabled="loading" @click="clear" />
          </div>
        </form>
      </section>

      <p v-if="error" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert">{{ error }}</p>
      <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-label="Pedidos para consulta de facturación">
        <div v-if="loading" class="p-10 text-center text-slate-600" role="status"><i class="pi pi-spin pi-spinner mr-2" aria-hidden="true"></i>Cargando pedidos…</div>
        <div v-else-if="!rows.length" class="p-10 text-center text-slate-600"><i class="pi pi-file text-3xl text-slate-300" aria-hidden="true"></i><p class="mt-2 font-semibold">No hay pedidos para estos filtros</p><p class="mt-1 text-sm">Prueba otro rango de fechas o tipo de comprobante.</p></div>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[1050px] text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr>
              <th class="p-4">Pedido / fecha</th><th class="p-4">Cliente</th><th class="p-4">Solicitud del cliente</th><th class="p-4">Receptor</th><th class="p-4 text-right">Total</th><th class="p-4">Pago</th><th class="p-4">Documento electrónico</th><th class="p-4">Datos</th><th class="p-4"></th>
            </tr></thead>
            <tbody>
              <tr v-for="row in rows" :key="row.id" class="border-t border-slate-100 hover:bg-slate-50">
                <td class="p-4"><b>{{ row.order_number }}</b><span class="mt-1 block text-xs text-slate-500">{{ formatDate(row.created_at) }}</span></td>
                <td class="p-4">{{ row.customer_name }}</td>
                <td class="p-4"><Tag :severity="row.requested_receipt_type ? 'info' : 'secondary'" :value="row.requested_receipt_type ? receiptLabel(row.requested_receipt_type) : 'Sin solicitud'" /><small v-if="row.requested_receipt_type" class="mt-1 block text-slate-500">Solicitud del cliente · revisar estado del documento</small></td>
                <td class="p-4"><span>{{ row.requested_recipient_name || 'No registrado' }}</span><small v-if="row.requested_identity_number" class="block text-slate-500">{{ row.requested_identity_type }} · {{ row.requested_identity_number }}</small></td>
                <td class="p-4 text-right font-semibold">S/ {{ money(row.total) }}</td>
                <td class="p-4">{{ paymentLabel(row.payment_status) }}</td>
                <td class="p-4"><template v-if="row.electronic_documents?.length"><div v-for="document in row.electronic_documents" :key="document.id" class="mb-1 last:mb-0"><Tag :severity="statusSeverity(document.status)" :value="document.status_label" /><small class="mt-1 block text-slate-500">{{ receiptLabel(document.document_type) }} · v{{ document.version }}</small></div></template><span v-else class="text-slate-500">Aún no preparado</span></td>
                <td class="p-4"><Tag :severity="row.receipt_data_missing ? 'warn' : 'success'" :value="row.receipt_data_missing ? 'Revisar datos' : 'Datos registrados'" /></td>
                <td class="p-4 text-right"><Button label="Consultar pedido" icon="pi pi-eye" size="small" outlined @click="openDetail(row)" /></td>
              </tr>
            </tbody>
          </table>
        </div>
        <footer v-if="pagination.total" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 p-4 text-sm">
          <span>Mostrando {{ pagination.from }}–{{ pagination.to }} de {{ pagination.total }}</span>
          <div class="flex gap-2"><Button label="Anterior" severity="secondary" outlined size="small" :disabled="loading || pagination.current_page <= 1" @click="load(pagination.current_page - 1)" /><Button label="Siguiente" severity="secondary" outlined size="small" :disabled="loading || pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)" /></div>
        </footer>
      </section>

      <div v-if="detailOpen" class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-3" role="presentation" @click.self="closeDetail" @keydown.esc="closeDetail">
        <section class="max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="detail-title">
          <header class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b bg-white p-5">
            <div><p class="text-xs font-bold uppercase tracking-wide text-blue-700">Pedido y documentos</p><h2 id="detail-title" class="mt-1 text-2xl font-black">{{ detail?.order_number || 'Pedido' }}</h2></div>
            <Button icon="pi pi-times" severity="secondary" text aria-label="Cerrar detalle" @click="closeDetail" />
          </header>
          <div v-if="detailLoading" class="p-8 text-center" role="status">Cargando detalle…</div>
          <p v-else-if="detailError && !detail" class="m-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert">{{ detailError }}</p>
          <div v-else-if="detail" class="space-y-5 p-5">
            <p class="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">{{ detail.receipt_status === 'requested_not_issued' ? 'Solicitud del cliente registrada. Revisa abajo el estado de los documentos electrónicos.' : 'Este pedido no tiene una solicitud de comprobante registrada.' }}</p>
            <p v-if="detail.requested_receipt_type === 'boleta'" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status">La boleta se genera, firma y valida individualmente en QA local antes de numerarse. Luego puede incluirse en un resumen diario simulado; no hay comunicación real con SUNAT.</p>
            <dl class="grid gap-3 sm:grid-cols-2">
              <div><dt class="label">Cliente</dt><dd>{{ detail.customer_name }}</dd></div><div><dt class="label">Fecha del pedido</dt><dd>{{ formatDate(detail.created_at) }}</dd></div>
              <div><dt class="label">Solicitud del cliente</dt><dd>{{ receiptLabel(detail.requested_receipt_type) }} <span v-if="detail.requested_receipt_type" class="text-slate-500">· solicitud registrada</span></dd></div><div><dt class="label">Estado de pago</dt><dd>{{ paymentLabel(detail.payment_status) }}</dd></div>
              <div><dt class="label">Receptor solicitado</dt><dd>{{ detail.requested_recipient_name || 'No registrado' }}</dd></div><div><dt class="label">Documento del receptor</dt><dd>{{ detail.requested_identity_type && detail.requested_identity_number ? `${detail.requested_identity_type} · ${detail.requested_identity_number}` : 'No registrado' }}</dd></div>
              <div><dt class="label">Correo</dt><dd>{{ detail.requested_receipt_email || 'No registrado' }}</dd></div><div><dt class="label">Dirección de facturación</dt><dd>{{ detail.requested_billing_address || 'No registrada' }}</dd></div>
              <div><dt class="label">Total del pedido</dt><dd class="font-bold">S/ {{ money(detail.total) }}</dd></div>
            </dl>
            <section aria-label="Productos del pedido"><h3 class="mb-2 font-bold">Productos del pedido</h3><ul class="divide-y rounded-lg border"><li v-for="(item, index) in detail.items || []" :key="index" class="flex justify-between gap-3 p-3 text-sm"><span>{{ item.quantity }} × {{ item.product_name }}</span><span>S/ {{ money(item.subtotal) }}</span></li></ul></section>

            <section class="space-y-3 rounded-xl border border-indigo-200 bg-indigo-50/50 p-4" aria-labelledby="documents-title">
              <div class="flex flex-wrap items-center justify-between gap-2"><div><h3 id="documents-title" class="font-bold">Documentos electrónicos</h3><p class="text-sm text-slate-600">La solicitud del pedido no es un documento emitido.</p></div><Button label="Preparar borrador" icon="pi pi-file-edit" size="small" :disabled="documentBusy || !electronicEnabled || !detail.requested_receipt_type" @click="confirmPrepareDraft" /></div>
              <p v-if="!electronicEnabled" class="text-sm text-amber-800" role="status">Módulo no habilitado. Puedes consultar la solicitud y los documentos históricos; no se permiten nuevas preparaciones ni modificaciones.</p>
              <p v-else-if="!detail.requested_receipt_type" class="text-sm text-slate-700" role="status">Este pedido no tiene solicitud de boleta o factura registrada.</p>
              <p v-if="documentError" class="text-sm text-red-700" role="alert">{{ documentError }}</p>
              <Button v-if="versionConflict && draft" label="Cargar versión vigente y conservar mis cambios" icon="pi pi-refresh" size="small" severity="secondary" outlined :disabled="documentBusy" @click="loadCurrentDraft" />
              <div v-if="detail.electronic_documents?.length" class="space-y-2" aria-label="Documentos del pedido">
                <button v-for="document in detail.electronic_documents" :key="document.id" type="button" class="flex w-full items-center justify-between gap-3 rounded-lg border bg-white p-3 text-left hover:border-blue-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" :disabled="documentBusy" @click="openDraft(document.id)">
                  <span><b>{{ receiptLabel(document.document_type) }}</b><small class="block text-slate-500">{{ document.status_label }} · versión {{ document.version }}</small></span><i class="pi pi-chevron-right" aria-hidden="true"></i>
                </button>
              </div>
              <p v-else class="text-sm text-slate-600">Todavía no se ha preparado un documento electrónico para esta solicitud.</p>

              <div v-if="draft" class="space-y-4 rounded-lg border bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div><p class="text-xs font-bold uppercase text-slate-500">Documento electrónico · {{ receiptLabel(draft.document_type) }}</p><h4 class="mt-1 font-bold">{{ draft.status_label }}</h4><p class="text-sm text-slate-600">{{ taxCalculationLabel(draft.tax_calculation_status) }}</p></div>
                  <div class="flex flex-wrap items-center gap-2"><Tag :value="draft.status_label" :severity="statusSeverity(draft.status)" /><span v-if="draft.result_code" class="rounded bg-slate-100 px-2 py-1 font-mono text-xs">Código {{ draft.result_code }}</span></div>
                </div>
                <p v-if="draft.series && draft.document_number" class="rounded bg-blue-50 p-3 text-sm font-semibold text-blue-950">Numeración de prueba: {{ draft.series }}-{{ String(draft.document_number).padStart(8, '0') }}</p>
                <p v-if="draft.result_message" class="rounded bg-slate-50 p-3 text-sm text-slate-800" role="status">{{ draft.result_message }}</p>
                <p v-if="['uncertain', 'submitted'].includes(draft.status)" class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status">Beta no ofrece una consulta de estado para este envío síncrono. No se enviará otra solicitud desde esta pantalla; conserva la numeración y revisa el resultado por el canal Beta autorizado.</p>
                <p v-if="draft.document_type === 'boleta'" class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status">La generación individual asigna y congela la numeración solo después de validar, firmar y almacenar el XML. Luego podrás incluir esta boleta en un resumen simulado. No hay comunicación externa ni CDR.</p>
                <p v-if="emissionReadiness && !emissionReadiness.ready" class="rounded bg-amber-50 p-3 text-sm text-amber-900" role="status"><b>{{ draft.document_type === 'boleta' ? 'No se puede generar localmente:' : 'No listo para emitir:' }}</b><ul class="mt-2 list-inside list-disc"><li v-for="reason in emissionReadiness.blocking_reasons" :key="reason">{{ blockingReasonLabel(reason) }}</li></ul></p>
                <p v-if="draft.amounts?.missing_fields?.length" class="rounded bg-amber-50 p-3 text-sm text-amber-900"><b>Datos pendientes:</b> {{ draft.amounts.missing_fields.map(missingFieldLabel).join(' · ') }}</p>

                <section class="rounded-lg border bg-slate-50 p-3" aria-label="Snapshots del documento">
                  <h5 class="font-bold">Snapshots utilizados por el documento</h5>
                  <p class="mt-1 text-xs text-slate-500">Vendedor: {{ sourceLabel(draft.seller?.source) }} · Receptor y productos: {{ sourceLabel(draft.recipient?.source) }}, {{ sourceLabel(draft.lines?.source) }}</p>
                  <dl class="mt-3 grid gap-3 sm:grid-cols-2"><div><dt class="label">Emisor</dt><dd>{{ draft.seller?.commercial_name || 'Pendiente' }} · {{ draft.seller?.legal_name || 'Razón social pendiente' }} · {{ draft.seller?.tax_id_number || 'Identificación pendiente' }} · {{ draft.seller?.fiscal_address || 'Dirección pendiente' }}</dd></div><div><dt class="label">Receptor</dt><dd>{{ draft.recipient?.name || 'Pendiente' }} · {{ draft.recipient?.document_type || 'Tipo pendiente' }} {{ draft.recipient?.document_number || '' }} · {{ draft.recipient?.billing_address || 'Dirección pendiente' }} · {{ draft.recipient?.email || 'Correo pendiente' }}</dd></div></dl>
                </section>

                <section v-if="draft.amounts?.tax_snapshot" class="rounded-lg border bg-slate-50 p-3 text-sm" aria-label="Desglose tributario guardado">
                  <h5 class="font-bold">Snapshot tributario del pedido</h5><p class="mt-1">Estado: {{ taxCalculationLabel(draft.tax_calculation_status) }}</p>
                  <ul class="mt-2 space-y-2"><li v-for="(item, index) in draft.lines?.items || []" :key="`tax-${index}`"><span class="font-medium">{{ item.name || 'Nombre histórico no disponible' }}</span><span v-if="item.tax?.tax_affectation"> — {{ item.tax.tax_affectation.label }}, base S/ {{ money(item.tax.base) }}, impuesto S/ {{ money(item.tax.tax) }}</span><span v-else> — afectación y cálculo pendientes</span></li></ul>
                  <p class="mt-2">IGV: S/ {{ money(draft.amounts.tax_snapshot.totals?.igv_minor) }} · IPM: S/ {{ money(draft.amounts.tax_snapshot.totals?.ipm_minor) }} · Envío: S/ {{ money(draft.amounts.tax_snapshot.shipping?.gross) }}</p>
                  <p v-if="draft.amounts.tax_snapshot.missing_fields?.includes('shipping_mixed_affectations')" class="mt-2 text-amber-900">El costo de envío de un pedido con afectaciones mixtas está pendiente de revisión contable; no se debe emitir hasta resolverlo.</p>
                </section>
                <p class="text-sm font-semibold">Total histórico del pedido: {{ draft.currency }} {{ draft.amounts?.total ?? 'No disponible' }}</p>

                <div v-if="draft.status === 'draft'" class="grid gap-3 sm:grid-cols-2">
                  <label v-for="field in sellerFields" :key="`s-${field.key}`" class="text-sm font-semibold">{{ field.label }}<input v-model="draft.seller[field.key]" class="field mt-1" :aria-label="field.label" :aria-invalid="Boolean(fieldError(`seller.${field.key}`))" @input="onDraftFieldInput(`seller.${field.key}`)" /><small v-if="fieldError(`seller.${field.key}`)" class="mt-1 block font-normal text-red-700">{{ fieldError(`seller.${field.key}`) }}</small></label>
                  <label v-for="field in recipientFields" :key="`r-${field.key}`" class="text-sm font-semibold">{{ field.label }}<input v-model="draft.recipient[field.key]" class="field mt-1" :aria-label="field.label" :aria-invalid="Boolean(fieldError(`recipient.${field.key}`))" @input="onDraftFieldInput(`recipient.${field.key}`)" /><small v-if="fieldError(`recipient.${field.key}`)" class="mt-1 block font-normal text-red-700">{{ fieldError(`recipient.${field.key}`) }}</small></label>
                  <div class="flex flex-wrap justify-end gap-2 sm:col-span-2"><Button label="Guardar correcciones" icon="pi pi-save" size="small" :disabled="documentBusy || !electronicEnabled" @click="saveDraft" /><Button v-if="['factura', 'boleta'].includes(draft.document_type)" :label="draft.document_type === 'boleta' ? 'Generar boleta de prueba' : 'Confirmar emisión de prueba'" :icon="draft.document_type === 'boleta' ? 'pi pi-file-check' : 'pi pi-send'" size="small" :disabled="documentBusy || !emissionReadiness?.ready || !electronicEnabled" @click="confirmIssue" /></div>
                </div>
                <p v-if="draft.status === 'draft' && !electronicEnabled" class="text-sm text-amber-800" role="status">El borrador es consultable, pero el módulo deshabilitado bloquea su preparación y modificación.</p>
                <ul class="divide-y rounded border"><li v-for="(item, index) in draft.lines?.items || []" :key="index" class="flex justify-between gap-3 p-2 text-sm"><span>{{ item.quantity }} × {{ item.name || 'Nombre histórico no disponible' }}</span><span>{{ item.subtotal ?? 'Importe no disponible' }}</span></li></ul>
                <div class="flex flex-wrap gap-2"><Button v-if="draft.files?.xml" label="Descargar XML" icon="pi pi-download" size="small" severity="secondary" outlined :disabled="documentBusy" @click="downloadXml" /><Button v-if="draft.files?.archive" label="Descargar ZIP" icon="pi pi-download" size="small" severity="secondary" outlined :disabled="documentBusy" @click="downloadArchive" /><Button v-if="draft.files?.cdr" label="Descargar CDR" icon="pi pi-download" size="small" severity="secondary" outlined :disabled="documentBusy" @click="downloadCdr" /><Button v-if="draft.files?.pdf" label="Representación PDF de prueba" icon="pi pi-file-pdf" size="small" severity="secondary" outlined :disabled="documentBusy" @click="downloadPdf" /></div>
                <ol v-if="draft.history?.length" class="space-y-1 border-t pt-3 text-xs text-slate-600" aria-label="Historial del documento"><li v-for="(event, index) in draft.history" :key="index">{{ eventLabel(event.event_type) }} · {{ event.actor_name }} · {{ formatDate(event.created_at) }} · versión {{ event.version_to }}</li></ol>
              </div>
            </section>
            <p v-if="detailError" class="text-sm text-red-700" role="alert">{{ detailError }}</p>
          </div>
        </section>
      </div>
    </main>
  </AdminLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import api from '@/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';

const toast = useToast();
const confirm = useConfirm();
const filters = reactive({ search: '', receipt_type: '', date_from: '', date_to: '', missing_data: false });
const rows = ref([]), pagination = ref({}), loading = ref(false), error = ref('');
const detailOpen = ref(false), detailLoading = ref(false), detail = ref(null), detailError = ref('');
const electronicEnabled = ref(false), documentBusy = ref(false), documentError = ref(''), draft = ref(null);
const draftErrors = ref({}), draftBase = ref(null), emissionReadiness = ref(null), versionConflict = ref(false), conflictEdits = ref(null);
const preparationKey = ref('');
const pendingPreparationKeys = new Map();
const eligibleReceipts = ref([]), receiptSummaries = ref([]), selectedReceiptIds = ref([]), selectedReceiptSummary = ref(null);
const summarySetupAvailable = ref(false), summarySetupReasons = ref([]);
const summaryLoading = ref(false), summaryBusy = ref(false), summaryError = ref('');
const canQueryReceiptSummary = computed(() => Boolean(selectedReceiptSummary.value?.local_simulation_id)
  && ['ticket_pending', 'uncertain'].includes(selectedReceiptSummary.value?.communication_status));
let listRequestSequence = 0;
let detailRequestSequence = 0;
const sellerFields = [{ key: 'commercial_name', label: 'Nombre comercial' }, { key: 'legal_name', label: 'Razón social' }, { key: 'tax_id_type', label: 'Tipo de identificación' }, { key: 'tax_id_number', label: 'Número tributario' }, { key: 'country_code', label: 'País' }, { key: 'fiscal_address', label: 'Dirección fiscal' }];
const recipientFields = [{ key: 'document_type', label: 'Tipo de documento' }, { key: 'document_number', label: 'Número de documento' }, { key: 'name', label: 'Receptor' }, { key: 'billing_address', label: 'Dirección de facturación' }, { key: 'email', label: 'Correo' }];

function money(value) { return Number(value || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function formatDate(value) { return value ? new Intl.DateTimeFormat('es-PE', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Lima' }).format(new Date(value)) : '—'; }
function receiptLabel(value) { return value === 'boleta' ? 'Boleta de venta' : value === 'factura' ? 'Factura' : 'No registrado'; }
function paymentLabel(value) { return ({ pending: 'Pendiente', partial: 'Parcial', approved: 'Aprobado', rejected: 'Rechazado', refunded: 'Reembolsado' })[value] || 'Sin estado'; }
function statusSeverity(value) { return ({ accepted: 'success', accepted_observed: 'info', rejected: 'danger', sunat_exception: 'danger', uncertain: 'warn', submitted: 'warn', processing: 'info', queued: 'info', draft: 'secondary' })[value] || 'secondary'; }
function communicationLabel(value) { return ({ not_started: 'Sin resultado', ticket_pending: 'Pendiente (LOCAL-SIM)', accepted: 'Aceptación simulada', accepted_observed: 'Aceptación con observaciones simulada', rejected: 'Rechazo simulado', soap_fault: 'SOAP Fault simulado', http_error: 'Error HTTP simulado', uncertain: 'Incierto simulado' })[value] || 'No disponible'; }
function summaryItemLabel(value) { return ({ queued: 'En cola local', simulated_ticket: 'Identificador local; pendiente', simulated_pending: 'Pendiente simulado', simulated_accepted: 'Aceptación simulada', simulated_accepted_observed: 'Aceptación con observaciones simulada', simulated_rejected: 'Rechazo simulado', simulated_soap_fault: 'SOAP Fault simulado', simulated_http_error: 'Error HTTP simulado', simulated_timeout: 'Timeout simulado', local_preparation_interrupted: 'Preparación local interrumpida' })[value] || 'Estado simulado'; }
function taxCalculationLabel(value) { return ({ complete: 'Cálculo tributario completo', pending_configuration: 'Pendiente de configuración o clasificación', review_required: 'Requiere revisión contable', pending_definition: 'Pedido histórico sin cálculo tributario registrado' })[value] || 'Cálculo tributario no disponible'; }
function blockingReasonLabel(value) {
  return ({
    tax_snapshot_incomplete: 'El cálculo tributario está incompleto o requiere revisión.', shipping_mixed_affectations: 'El envío de un pedido con afectaciones mixtas requiere revisión contable.', shipping_treatment_unresolved: 'El tratamiento tributario del envío está pendiente.',
    lines_missing: 'El pedido no tiene líneas de producto.', line_snapshot_unsupported: 'Una línea no tiene datos tributarios compatibles.', seller_ruc_invalid: 'El RUC del emisor no tiene el formato requerido.', seller_commercial_name_missing: 'Falta el nombre comercial del emisor.', seller_legal_name_missing: 'Falta la razón social del emisor.', seller_country_unsupported: 'El país del emisor no está soportado.', operation_type_unsupported: 'El tipo de operación no está soportado.',
    payment_form_unsupported: 'El pedido requiere pago completo aprobado; anticipos y pagos parciales están pendientes de tratamiento.', invoice_recipient_ruc_required: 'La factura requiere un receptor identificado con RUC.', receipt_recipient_identity_required: 'La boleta requiere identificar al receptor según la solicitud guardada.', receipt_recipient_identity_type_unsupported: 'El tipo de identificación del receptor no es compatible con el resumen diario.', document_type_unsupported: 'El tipo de documento no está soportado.', currency_unsupported: 'Solo se admite moneda PEN en este alcance.', daily_summary_selection_required: 'Selecciona la boleta en el resumen diario para preparar su paquete local.', official_summary_schema_missing_or_invalid: 'Falta configurar el conjunto oficial completo de esquemas del resumen diario.', local_qa_only: 'La preparación simulada está habilitada únicamente en QA local.', testing_direct_mode_required: 'Selecciona el ambiente de pruebas y el modo directo de QA.', durable_queue_connection_mismatch: 'La cola debe usar la misma base transaccional de la aplicación.', requested_document_type_mismatch: 'El documento no coincide con el tipo solicitado en el pedido.', advance_treatment_pending: 'El tratamiento de pagos parciales o anticipos está pendiente.',
    provider_adapter_unavailable: 'El proveedor configurado no está disponible.', test_environment_only: 'Esta fase permite únicamente el ambiente de pruebas.', signing_certificate_missing_or_expired: 'Falta un certificado de firma vigente en almacenamiento privado.', php_zip_extension_missing: 'PHP no tiene habilitada la extensión ZIP.', php_xml_or_openssl_extension_missing: 'El runtime PHP no tiene XML u OpenSSL disponible.', durable_queue_unavailable: 'No está disponible la cola durable de procesamiento.', official_ubl_schema_missing_or_invalid: 'Falta el conjunto local de esquemas UBL requerido.', commercial_module_disabled: 'El módulo está deshabilitado comercialmente.',
  })[value] || 'Hay una condición pendiente de revisión antes de emitir.';
}
function missingFieldLabel(value) {
  const labels = { 'order.items': 'productos del pedido', 'seller.commercial_name': 'nombre comercial del emisor', 'seller.legal_name': 'razón social del emisor', 'seller.tax_id_type': 'tipo de identificación del emisor', 'seller.tax_id_number': 'identificación del emisor', 'seller.country_code': 'país del emisor', 'seller.fiscal_address': 'dirección fiscal del emisor', 'recipient.document_type': 'tipo de documento del receptor', 'recipient.document_number': 'número de documento del receptor', 'recipient.name': 'nombre del receptor', 'recipient.email': 'correo del receptor', 'lines.name': 'nombre histórico de producto', 'lines.amount': 'importes históricos de productos' };
  if (/^lines\.\d+\.name$/.test(value)) return 'nombre histórico de producto';
  if (/^lines\.\d+\.amount$/.test(value)) return 'importe histórico de producto';
  return labels[value] || value.replaceAll('_', ' ');
}
function sourceLabel(value) { return ({ current_business_profile: 'perfil vigente del negocio', order_checkout_snapshot: 'solicitud guardada al comprar', order_item_snapshots: 'líneas históricas del pedido' })[value] || 'snapshot guardado'; }
function eventLabel(value) { return ({ prepared: 'Borrador preparado', updated: 'Borrador corregido', confirmed: 'Confirmado para procesamiento', status_queried: 'Resultado consultado', processed: 'Procesamiento actualizado' })[value] || 'Evento del documento'; }
function fieldError(path) { return draftErrors.value[path]?.[0] || ''; }
function clearFieldError(path) { delete draftErrors.value[path]; }
function onDraftFieldInput(path) { clearFieldError(path); emissionReadiness.value = null; }
function formChanges(current, baseline, fields) {
  return Object.fromEntries(fields.filter(({ key }) => (current?.[key] ?? null) !== (baseline?.[key] ?? null)).map(({ key }) => [key, current?.[key] ?? null]));
}
function setDraft(document) { draft.value = document; emissionReadiness.value = document?.readiness ?? null; draftBase.value = { seller: { ...(document.seller || {}) }, recipient: { ...(document.recipient || {}) } }; }

async function loadElectronicStatus() {
  try { electronicEnabled.value = Boolean((await api.get('/billing/electronic-invoicing/status')).data.data.commercial_enabled); }
  catch { electronicEnabled.value = false; }
}
async function refreshReceiptSummaries() {
  summaryLoading.value = true; summaryError.value = '';
  try {
    const [eligibleResponse, summaryResponse] = await Promise.all([
      api.get('/billing/receipt-summaries/eligible'),
      api.get('/billing/receipt-summaries'),
    ]);
    eligibleReceipts.value = eligibleResponse.data.data || [];
    summarySetupAvailable.value = Boolean(eligibleResponse.data.meta?.preparation_available);
    summarySetupReasons.value = eligibleResponse.data.meta?.blocking_reasons || [];
    receiptSummaries.value = summaryResponse.data.data || [];
    selectedReceiptIds.value = selectedReceiptIds.value.filter(id => eligibleReceipts.value.some(receipt => receipt.id === id));
  } catch (exception) { summaryError.value = exception.response?.data?.message || 'No se pudieron cargar los borradores o resúmenes.'; }
  finally { summaryLoading.value = false; }
}
function confirmReceiptSummary() {
  if (summaryBusy.value || !electronicEnabled.value || !summarySetupAvailable.value || selectedReceiptIds.value.length === 0) return;
  confirm.require({
    header: 'Preparar resumen diario local',
    message: 'Se incluirán en un resumen las boletas que ya están firmadas, numeradas y congeladas. Se generará un resultado simulado localmente; no se enviará ni consultará SUNAT y no se fabricará una CDR. ¿Continuar?',
    acceptLabel: 'Incluir en resumen simulado', rejectLabel: 'Cancelar',
    accept: prepareReceiptSummary,
  });
}
async function prepareReceiptSummary() {
  if (summaryBusy.value || !electronicEnabled.value || !summarySetupAvailable.value || selectedReceiptIds.value.length === 0) return;
  summaryBusy.value = true; summaryError.value = '';
  const selected = eligibleReceipts.value.filter(receipt => selectedReceiptIds.value.includes(receipt.id)).map(receipt => ({ id: receipt.id, version: receipt.version }));
  try {
    const response = await api.post('/billing/receipt-summaries', { documents: selected });
    selectedReceiptSummary.value = response.data.data;
    selectedReceiptIds.value = [];
    await refreshReceiptSummaries();
    await openReceiptSummary(response.data.data.id);
    await load(1);
    toast.add({ severity: 'info', summary: 'Resumen procesado localmente', detail: 'La comunicación es simulada; no hubo envío a SUNAT.', life: 6000 });
  } catch (exception) { summaryError.value = exception.response?.data?.message || 'No se confirmó la respuesta. Revisa la misma selección antes de reintentar.'; }
  finally { summaryBusy.value = false; }
}
async function openReceiptSummary(id) {
  summaryBusy.value = true; summaryError.value = '';
  try { selectedReceiptSummary.value = (await api.get(`/billing/receipt-summaries/${id}`)).data.data; }
  catch (exception) { summaryError.value = exception.response?.data?.message || 'No se pudo cargar el resumen.'; }
  finally { summaryBusy.value = false; }
}
async function queryReceiptSummary() {
  if (!selectedReceiptSummary.value || !canQueryReceiptSummary.value || summaryBusy.value || !electronicEnabled.value) return;
  summaryBusy.value = true; summaryError.value = '';
  try {
    const id = selectedReceiptSummary.value.id;
    selectedReceiptSummary.value = (await api.post(`/billing/receipt-summaries/${id}/query`, {})).data.data;
    await refreshReceiptSummaries();
  } catch (exception) { summaryError.value = exception.response?.data?.message || 'No se pudo completar la consulta local simulada.'; }
  finally { summaryBusy.value = false; }
}
async function downloadReceiptSummary(type) {
  if (!selectedReceiptSummary.value || summaryBusy.value) return;
  summaryBusy.value = true;
  try {
    const response = await api.get(`/billing/receipt-summaries/${selectedReceiptSummary.value.id}/files/${type}`, { responseType: 'blob' });
    const url = URL.createObjectURL(response.data); const link = document.createElement('a'); link.href = url;
    link.download = `SIMULACION-${selectedReceiptSummary.value.document_id}.${type === 'archive' ? 'zip' : 'xml'}`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 0);
  } catch { summaryError.value = 'No se pudo descargar el archivo privado del resumen.'; }
  finally { summaryBusy.value = false; }
}

async function load(page = 1) {
  const requestId = ++listRequestSequence;
  loading.value = true;
  error.value = '';
  try {
    const params = Object.fromEntries(Object.entries({ ...filters, page }).filter(([, value]) => value !== '' && value !== false));
    const response = (await api.get('/billing/orders', { params })).data;
    if (requestId !== listRequestSequence) return;
    rows.value = response.data || [];
    pagination.value = response.meta || {};
  } catch (exception) {
    if (requestId === listRequestSequence) error.value = exception.response?.data?.message || 'No se pudo cargar la bandeja.';
  } finally {
    if (requestId === listRequestSequence) loading.value = false;
  }
}
function clear() { Object.assign(filters, { search: '', receipt_type: '', date_from: '', date_to: '', missing_data: false }); load(1); }
async function refreshOrder(id) { detail.value = (await api.get(`/billing/orders/${id}`)).data.data; }
function readPreparationKey(orderId) { try { return pendingPreparationKeys.get(orderId) || sessionStorage.getItem(preparationStorageKey(orderId)) || ''; } catch { return pendingPreparationKeys.get(orderId) || ''; } }
function persistPreparationKey(orderId, key) { pendingPreparationKeys.set(orderId, key); try { sessionStorage.setItem(preparationStorageKey(orderId), key); } catch { /* La clave sigue en memoria para reintentos de esta sesión. */ } }
function clearPreparationKey(orderId) { pendingPreparationKeys.delete(orderId); try { sessionStorage.removeItem(preparationStorageKey(orderId)); } catch { /* El almacenamiento de sesión es opcional. */ } }
function preparationStorageKey(orderId) { return `billing.electronic-document.preparation.${orderId}`; }
function closeDetail() { detailOpen.value = false; detailRequestSequence++; }

async function openDetail(row) {
  const requestId = ++detailRequestSequence;
  detail.value = null; draft.value = null; draftBase.value = null; emissionReadiness.value = null; versionConflict.value = false; conflictEdits.value = null;
  preparationKey.value = readPreparationKey(row.id); detailError.value = ''; documentError.value = ''; draftErrors.value = {}; electronicEnabled.value = false;
  detailOpen.value = true; detailLoading.value = true;
  try {
    const [orderResponse, status] = await Promise.all([api.get(`/billing/orders/${row.id}`), api.get('/billing/electronic-invoicing/status')]);
    if (requestId === detailRequestSequence) {
      detail.value = orderResponse.data.data;
      electronicEnabled.value = Boolean(status.data.data.commercial_enabled);
    }
  } catch (exception) {
    if (requestId === detailRequestSequence) detailError.value = exception.response?.data?.message || 'No se pudo cargar el pedido o el estado del módulo.';
  } finally { if (requestId === detailRequestSequence) detailLoading.value = false; }
}
function randomKey() { if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID(); const bytes = new Uint8Array(16); globalThis.crypto.getRandomValues(bytes); bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80; const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join(''); return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`; }
function confirmPrepareDraft() { if (documentBusy.value || !electronicEnabled.value || !detail.value?.requested_receipt_type) return; confirm.require({ header: 'Preparar borrador', message: 'Se creará un borrador a partir de los snapshots del pedido. No se emitirá ningún comprobante. ¿Deseas continuar?', acceptLabel: 'Preparar borrador', rejectLabel: 'Cancelar', accept: prepareDraft }); }
async function prepareDraft() {
  if (!detail.value || documentBusy.value || !electronicEnabled.value) return;
  documentBusy.value = true; documentError.value = ''; draftErrors.value = {}; emissionReadiness.value = null;
  const orderId = detail.value.id;
  if (!preparationKey.value) { preparationKey.value = randomKey(); persistPreparationKey(orderId, preparationKey.value); }
  try {
    const response = await api.post(`/billing/orders/${orderId}/electronic-documents`, { preparation_key: preparationKey.value });
    setDraft(response.data.data); preparationKey.value = ''; clearPreparationKey(orderId);
    try { await refreshOrder(orderId); } catch { detailError.value = 'El borrador se preparó; no se pudo actualizar la lista del pedido.'; }
    toast.add({ severity: 'success', summary: 'Borrador preparado', detail: 'La solicitud sigue sin estar emitida.', life: 4500 });
  } catch (exception) { documentError.value = exception.response?.data?.message || 'No se confirmó la respuesta. Reintenta desde este pedido para recuperar el mismo borrador.'; }
  finally { documentBusy.value = false; }
}
async function fetchDraft(id) {
  const document = (await api.get(`/billing/electronic-documents/${id}`)).data.data;
  return { document, readiness: document.readiness ?? null };
}
async function openDraft(id) {
  documentBusy.value = true; documentError.value = ''; draftErrors.value = {};
  try { const result = await fetchDraft(id); setDraft(result.document); emissionReadiness.value = result.readiness; versionConflict.value = false; conflictEdits.value = null; }
  catch (exception) { documentError.value = exception.response?.data?.message || 'No se pudo cargar el documento.'; }
  finally { documentBusy.value = false; }
}
async function loadCurrentDraft() {
  if (!draft.value || !versionConflict.value || documentBusy.value) return;
  const id = draft.value.id; const local = conflictEdits.value;
  documentBusy.value = true;
  try {
    const result = await fetchDraft(id);
    setDraft(result.document);
    draft.value = { ...result.document, seller: { ...result.document.seller, ...(local?.seller || {}) }, recipient: { ...result.document.recipient, ...(local?.recipient || {}) } };
    emissionReadiness.value = result.readiness; versionConflict.value = false; conflictEdits.value = null; documentError.value = 'Se cargó la versión vigente; tus cambios siguen en el formulario. Revísalos antes de guardar.';
  } catch (exception) { documentError.value = exception.response?.data?.message || 'No se pudo cargar la versión vigente; se conservaron tus cambios.'; }
  finally { documentBusy.value = false; }
}
function confirmIssue() {
  if (!draft.value || !['factura', 'boleta'].includes(draft.value.document_type) || documentBusy.value || !electronicEnabled.value || !emissionReadiness.value?.ready) return;
  const isReceipt = draft.value.document_type === 'boleta';
  confirm.require({
    header: isReceipt ? 'Generar boleta local de prueba' : 'Confirmar emisión de prueba',
    message: isReceipt
      ? 'Se asignará numeración y se congelarán los snapshots solo después de firmar, validar y guardar el XML local. Esta acción no incluirá la boleta en un resumen ni llamará a SUNAT.'
      : 'Se congelará este borrador, se asignará numeración de prueba y se enviará a SUNAT Beta. La respuesta definitiva depende de SOAP/CDR; HTTP 200 por sí solo no significa aceptación.',
    acceptLabel: isReceipt ? 'Generar localmente' : 'Confirmar envío de prueba', rejectLabel: 'Cancelar', accept: issueDraft,
  });
}
async function issueDraft() {
  if (!draft.value || !['factura', 'boleta'].includes(draft.value.document_type) || documentBusy.value || !electronicEnabled.value || !emissionReadiness.value?.ready) return;
  documentBusy.value = true; documentError.value = '';
  const id = draft.value.id; const orderId = detail.value.id;
  try {
    setDraft((await api.post(`/billing/electronic-documents/${id}/issue`, { version: draft.value.version })).data.data);
    emissionReadiness.value = (await api.get(`/billing/electronic-documents/${id}/readiness`)).data.data;
    await refreshOrder(orderId);
    await load(pagination.value.current_page || 1);
    await refreshReceiptSummaries();
    toast.add({ severity: 'info', summary: draft.value.document_type === 'boleta' ? 'Boleta generada localmente' : 'Envío de prueba procesado', detail: draft.value.document_type === 'boleta' ? 'XML firmado y almacenado. Aún no está incluida en un resumen ni comunicada.' : 'Revisa el estado documental y la respuesta recibida; no se infiere aceptación por el HTTP.', life: 6000 });
  } catch (exception) {
    documentError.value = exception.response?.data?.message || 'No se confirmó la respuesta. Se consultará el mismo documento para comprobar si quedó procesado; no se repetirá el envío automáticamente.';
    try { const result = await fetchDraft(id); setDraft(result.document); emissionReadiness.value = result.readiness; await refreshOrder(orderId); }
    catch { /* El mensaje mantiene al operador en el mismo documento para revisión manual. */ }
  } finally { documentBusy.value = false; }
}
async function downloadFile(type) {
  if (!draft.value || documentBusy.value) return;
  documentBusy.value = true; documentError.value = '';
  try { const response = await api.get(`/billing/electronic-documents/${draft.value.id}/files/${type}`, { responseType: 'blob' }); const url = URL.createObjectURL(response.data); const link = document.createElement('a'); link.href = url; link.download = `${draft.value.document_type === 'boleta' ? 'SIMULACION-' : ''}${draft.value.series || 'document'}-${String(draft.value.document_number || '').padStart(8, '0')}-${type === 'pdf' ? 'PRUEBA.pdf' : `${type}.${type === 'archive' ? 'zip' : 'xml'}`}`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 0); }
  catch { documentError.value = 'No se pudo descargar el archivo privado.'; }
  finally { documentBusy.value = false; }
}
function downloadXml() { return downloadFile('xml'); }
function downloadArchive() { return downloadFile('archive'); }
function downloadCdr() { return downloadFile('cdr'); }
function downloadPdf() { return downloadFile('pdf'); }
async function saveDraft() {
  if (!draft.value || documentBusy.value || !electronicEnabled.value) return;
  documentBusy.value = true; documentError.value = ''; draftErrors.value = {}; versionConflict.value = false; conflictEdits.value = null; emissionReadiness.value = null;
  const id = draft.value.id; const orderId = detail.value.id;
  try {
    setDraft((await api.put(`/billing/electronic-documents/${id}`, { version: draft.value.version, seller: Object.fromEntries(sellerFields.map(field => [field.key, draft.value.seller[field.key] || null])), recipient: Object.fromEntries(recipientFields.map(field => [field.key, draft.value.recipient[field.key] || null])) })).data.data);
    await refreshOrder(orderId); toast.add({ severity: 'success', summary: 'Borrador actualizado', detail: 'Los cambios se guardaron en la versión vigente.', life: 4500 });
  } catch (exception) {
    versionConflict.value = exception.response?.status === 409;
    if (versionConflict.value) conflictEdits.value = { seller: formChanges(draft.value.seller, draftBase.value?.seller, sellerFields), recipient: formChanges(draft.value.recipient, draftBase.value?.recipient, recipientFields) };
    draftErrors.value = exception.response?.data?.errors || {};
    documentError.value = versionConflict.value ? 'El borrador cambió en otra sesión. Carga la versión vigente para revisar tus cambios antes de guardar.' : exception.response?.data?.message || 'No se pudo guardar el borrador. Tus datos permanecen en el formulario.';
  } finally { documentBusy.value = false; }
}
onMounted(() => load());
onMounted(() => { loadElectronicStatus(); refreshReceiptSummaries(); });
</script>

<style scoped>
.field{min-height:2.75rem;width:100%;border:1px solid #cbd5e1;border-radius:.625rem;background:#fff;padding:.625rem .75rem;color:#0f172a}.field:focus-visible{outline:2px solid #2563eb;outline-offset:2px}.field[aria-invalid="true"]{border-color:#b91c1c}.label{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#64748b}.label+dd{margin-top:.25rem;overflow-wrap:anywhere;color:#0f172a}
</style>
