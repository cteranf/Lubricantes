<template>
    <section class="space-y-5" aria-labelledby="receipts-title">
        <header class="flex flex-col gap-4 rounded-2xl bg-slate-900 p-5 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-6">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.16em] text-blue-300">Inventario</p>
                <h2 id="receipts-title" class="mt-1 text-2xl font-black tracking-tight">Recepciones masivas</h2>
                <p class="mt-2 text-sm text-slate-300">Registra entradas manuales de productos y confirma el documento una sola vez.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button label="Volver a inventario" icon="pi pi-arrow-left" severity="secondary" outlined @click="$emit('close')" />
                <Button label="Nuevo borrador" icon="pi pi-plus" :disabled="ambiguousCreate" @click="startNewReceipt" />
            </div>
        </header>

        <div v-if="ambiguousCreate" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="alert">
            <p class="font-bold">No se recibió la respuesta al crear el borrador.</p>
            <p class="mt-1">No vuelvas a enviarlo todavía. Actualiza la lista y abre el borrador si aparece; la API actual no permite consultar por una creación cuyo ID no llegó al navegador.</p>
            <Button class="mt-3" label="Actualizar borradores" icon="pi pi-refresh" severity="warn" outlined :loading="listLoading" @click="loadReceipts(1)" />
            <Button v-if="ambiguousCreateListChecked" class="mt-3 sm:ml-2" label="No aparece: conservar formulario y permitir reintento" icon="pi pi-pencil" severity="secondary" outlined :disabled="listLoading" @click="allowCreateRetry" />
            <p v-if="ambiguousCreateListChecked" class="mt-2 text-xs">Revisa la lista visible antes de continuar. El formulario conserva el almacén, las líneas y las cantidades.</p>
        </div>

        <div v-if="pageError" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">{{ pageError }}</div>

        <div class="grid gap-5 xl:grid-cols-[minmax(17rem,22rem)_minmax(0,1fr)]">
            <aside class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-label="Recepciones guardadas">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-4">
                    <div><h3 class="font-bold text-slate-900">Documentos</h3><p class="text-xs text-slate-500">Borradores y recepciones confirmadas</p></div>
                    <Button icon="pi pi-refresh" text rounded aria-label="Actualizar documentos" :loading="listLoading" @click="loadReceipts(meta.current_page || 1)" />
                </div>
                <div v-if="listLoading && !receipts.length" class="p-6 text-center text-sm text-slate-500" role="status">Cargando recepciones…</div>
                <div v-else-if="!receipts.length" class="p-6 text-center text-sm text-slate-500">Todavía no hay recepciones. Crea un borrador para comenzar.</div>
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="item in receipts" :key="item.id">
                        <button type="button" class="w-full px-4 py-3 text-left transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-blue-600" :class="Number(receipt?.id) === Number(item.id) ? 'bg-blue-50' : ''" :aria-current="Number(receipt?.id) === Number(item.id) ? 'true' : undefined" @click="openReceipt(item.id)">
                            <span class="flex items-center justify-between gap-2"><span class="font-mono text-sm font-bold text-slate-900">{{ item.reference }}</span><Tag :value="statusLabel(item.status)" :severity="statusSeverity(item.status)" /></span>
                            <span class="mt-1 block truncate text-sm text-slate-700">{{ item.warehouse?.name || 'Almacén no disponible' }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ item.lines?.length || 0 }} productos · {{ units(item.lines) }} unidades</span>
                        </button>
                    </li>
                </ul>
                <AdminPagination v-if="meta.last_page > 1" :page="meta.current_page || 1" :last-page="meta.last_page" :summary="`${meta.total || 0} recepciones`" :loading="listLoading" @change="loadReceipts" />
            </aside>

            <main class="min-w-0 space-y-4">
                <div v-if="!creatingNew && !receipt" class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                    <i class="pi pi-inbox text-3xl text-slate-400" aria-hidden="true"></i>
                    <h3 class="mt-3 font-bold text-slate-800">Selecciona una recepción</h3>
                    <p class="mt-1 text-sm text-slate-500">Abre un documento guardado o crea un borrador nuevo.</p>
                </div>

                <template v-else>
                    <div v-if="conflictMessage" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="alert">
                        <p class="font-bold">{{ conflictMessage }}</p>
                        <Button class="mt-3" label="Cargar versión vigente" icon="pi pi-refresh" severity="warn" outlined :loading="statusLoading" @click="loadCurrentVersion" />
                    </div>
                    <div v-if="recoveryMessage" class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950" role="status">
                        <p class="font-bold">{{ recoveryMessage }}</p>
                        <Button v-if="statusUncertain" class="mt-3" label="Consultar estado" icon="pi pi-refresh" severity="info" outlined :loading="statusLoading" @click="recoverReceiptState('confirm')" />
                        <Button v-else-if="retrySaveAvailable" class="mt-3" label="Reintentar guardado de este borrador" icon="pi pi-save" severity="info" outlined :disabled="busy" @click="saveDraft" />
                        <Button v-else-if="retryConfirmAvailable" class="mt-3" label="Revisar y reintentar esta recepción" icon="pi pi-check" severity="info" outlined :disabled="busy" @click="openConfirmation" />
                    </div>

                    <section v-if="creatingNew || receipt?.status === 'draft'" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-labelledby="receipt-editor-title">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div><p class="text-xs font-bold uppercase tracking-wider text-blue-700">{{ creatingNew ? 'Nuevo documento' : receipt.reference }}</p><h3 id="receipt-editor-title" class="mt-1 text-xl font-black text-slate-900">{{ creatingNew ? 'Crear borrador' : 'Editar borrador' }}</h3><p class="mt-1 text-sm text-slate-600">Los borradores no cambian existencias. El ingreso ocurre al confirmar.</p></div>
                            <Tag v-if="receipt" :value="`Versión ${receipt.version}`" severity="info" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block text-sm font-semibold text-slate-700">Almacén activo
                                <Select v-model="form.warehouse_id" class="mt-1 w-full" :options="warehouseOptions" option-label="label" option-value="id" option-disabled="disabled" placeholder="Selecciona un almacén" :disabled="busy || statusLoading || importBusy" />
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">Referencia externa (opcional)
                                <InputText v-model="form.external_reference" class="mt-1 w-full" maxlength="100" :disabled="busy || statusLoading || importBusy" placeholder="Orden de compra, guía, etc." />
                            </label>
                        </div>
                        <label class="block text-sm font-semibold text-slate-700">Notas (opcional)
                            <InputText v-model="form.notes" class="mt-1 w-full" maxlength="2000" :disabled="busy || statusLoading || importBusy" placeholder="Observaciones de la recepción" />
                        </label>

                        <section class="space-y-3 rounded-xl border border-blue-200 bg-blue-50/60 p-4" aria-labelledby="receipt-import-title">
                            <div>
                                <h4 id="receipt-import-title" class="font-bold text-slate-900">Importar productos</h4>
                                <p class="mt-1 text-xs text-slate-600">CSV UTF-8 o Excel XLSX, máximo 2 MB, con las columnas sku y cantidad. La vista previa no guarda ni modifica existencias.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Button type="button" label="Importar Excel o CSV" icon="pi pi-upload" severity="secondary" outlined :disabled="!form.warehouse_id || busy || statusLoading || importBusy" @click="openImportFilePicker" />
                                <input ref="importFileInput" class="sr-only" type="file" accept=".xlsx,.csv" aria-label="Seleccionar archivo Excel o CSV" @change="onImportFileChange" />
                                <Button type="button" label="Descargar plantilla" icon="pi pi-download" severity="secondary" text :disabled="importBusy" @click="downloadImportTemplate" />
                                <Button v-if="importFile" type="button" label="Validar y previsualizar" icon="pi pi-search" :loading="importBusy" :disabled="!form.warehouse_id || busy || statusLoading" @click="previewImport" />
                                <span v-if="importFile" class="text-xs text-slate-600" aria-live="polite">{{ importFile.name }} · {{ formatFileSize(importFile.size) }}</span>
                            </div>
                            <p v-if="importError" class="text-sm text-red-700" role="alert">{{ importError }}</p>
                            <div v-if="importPreview" class="space-y-3" aria-live="polite">
                                <p class="text-sm font-semibold text-slate-800">{{ importPreview.totals?.importable_products || 0 }} filas válidas · {{ importPreview.totals?.total_products || lines.length }} productos totales · {{ Number(importPreview.totals?.total_units || totalUnits).toLocaleString('es-PE') }} unidades totales</p>
                                <ul v-if="importPreview.global_errors?.length" class="list-inside list-disc rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                                    <li v-for="(message, index) in importPreview.global_errors" :key="`import-global-${index}`">{{ message }}</li>
                                </ul>
                                <div v-if="importPreview.rows?.length" class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                    <table class="min-w-full text-left text-sm">
                                        <thead class="bg-slate-100 text-xs uppercase text-slate-600"><tr><th scope="col" class="px-3 py-2">Fila</th><th scope="col" class="px-3 py-2">SKU</th><th scope="col" class="px-3 py-2">Producto</th><th scope="col" class="px-3 py-2 text-right">Cantidad</th><th scope="col" class="px-3 py-2">Resultado</th></tr></thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <tr v-for="row in importPreview.rows" :key="`import-row-${row.row}`" :class="row.errors?.length ? 'bg-red-50/70' : ''">
                                                <td class="px-3 py-2 font-mono">{{ row.row }}</td><td class="px-3 py-2 font-mono">{{ row.sku || '—' }}</td><td class="px-3 py-2">{{ row.product_name || '—' }}</td><td class="px-3 py-2 text-right">{{ row.quantity ?? '—' }}</td><td class="px-3 py-2"><span v-if="row.errors?.length" class="text-red-700">{{ row.errors.join(' ') }}</span><span v-else class="text-emerald-700">Válida</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-if="!importPreview.can_import && importPreview.rows?.length" class="text-sm text-amber-800" role="status">Corrige los errores del archivo o del borrador. No se ha incorporado ninguna fila.</p>
                                <Button v-if="importPreview.can_import" type="button" label="Incorporar filas al borrador" icon="pi pi-plus" severity="success" outlined :disabled="busy || importBusy" @click="appendImportedRows" />
                            </div>
                        </section>

                        <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-4" aria-labelledby="receipt-scan-title">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-blue-700">Identificación de productos</p>
                                <h4 id="receipt-scan-title" class="mt-1 text-lg font-black text-slate-900">Recepción por QR</h4>
                                <p class="mt-1 text-sm text-slate-600">El QR identifica el producto; el stock cambia únicamente al confirmar esta recepción.</p>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-2"><Tag :value="creatingNew ? 'Nuevo borrador' : statusLabel(receipt?.status)" :severity="creatingNew ? 'info' : statusSeverity(receipt?.status)" /><span class="text-xs font-mono text-slate-600">{{ receipt?.reference || 'Número: se asignará al guardar' }}</span><span class="text-xs font-semibold text-slate-600">Almacén: {{ selectedWarehouseLabel }}</span></div>
                            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                                <label class="text-sm font-semibold text-slate-700" for="receipt-count-mode">Modo de conteo
                                    <Select id="receipt-count-mode" v-model="countMode" class="mt-1 w-full" :options="countModeOptions" option-label="label" option-value="value" :disabled="busy || statusLoading || importBusy" />
                                </label>
                                <Button v-if="!cameraOpen" type="button" label="Abrir cámara" icon="pi pi-camera" severity="secondary" outlined :disabled="!form.warehouse_id || busy || statusLoading || importBusy" @click="openCamera" />
                                <Button v-else type="button" label="Cerrar cámara" icon="pi pi-stop-circle" severity="secondary" outlined :disabled="scannerStarting" @click="closeCamera" />
                            </div>
                            <label class="block text-sm font-semibold text-slate-700" for="receipt-scan-code">Escanear con lector USB/Bluetooth o escribir SKU/código
                                <InputText id="receipt-scan-code" v-model="scanCode" class="mt-1 w-full font-mono" maxlength="120" autocomplete="off" autocapitalize="off" spellcheck="false" :disabled="!form.warehouse_id || scanBusy || busy || statusLoading || importBusy" placeholder="Enfoca aquí y termina cada lectura con Enter" @keydown.enter.prevent="submitScanCode" />
                            </label>
                            <p v-if="scanMessage" class="text-sm" :class="scanError ? 'text-red-700' : 'text-slate-600'" :role="scanError ? 'alert' : 'status'" aria-live="polite">{{ scanMessage }}</p>
                            <div v-if="cameraOpen" class="space-y-3 rounded-lg bg-slate-950 p-3 sm:p-4">
                                <div class="relative mx-auto w-full max-w-xl overflow-hidden rounded-lg border border-slate-700 bg-black">
                                    <video ref="cameraVideo" class="mx-auto max-h-72 min-h-52 w-full object-contain" muted playsinline aria-label="Vista previa de la cámara para leer códigos QR" />
                                    <div class="pointer-events-none absolute inset-0 flex items-center justify-center" aria-hidden="true"><div class="h-2/3 w-2/3 rounded-2xl border-2 border-blue-400 shadow-[0_0_0_999px_rgba(2,6,23,.18)]" /></div>
                                </div>
                                <div v-if="cameraList.length > 1" class="mx-auto max-w-xl">
                                    <label class="text-sm font-medium text-white" for="receipt-camera-select">Cámara
                                        <Select id="receipt-camera-select" v-model="selectedCameraId" class="mt-1 w-full" :options="cameraList" option-label="label" option-value="id" @update:model-value="changeCamera" />
                                    </label>
                                </div>
                                <div class="flex flex-wrap justify-center gap-2">
                                    <Button v-if="cameraPaused" type="button" label="Reanudar escaneo" icon="pi pi-refresh" :disabled="scannerStarting || scanBusy || !form.warehouse_id" @click="resumeCamera" />
                                    <Button v-else-if="!scannerStarting" type="button" label="Pausar lectura" icon="pi pi-pause" severity="secondary" outlined @click="pauseCamera" />
                                    <Button v-if="scannerStarting" type="button" label="Iniciando cámara…" icon="pi pi-spin pi-spinner" disabled />
                                </div>
                            </div>
                            <p v-if="cameraMessage" class="text-sm text-amber-800" role="status" aria-live="polite">{{ cameraMessage }}</p>
                            <div v-if="scanCandidate" class="flex flex-col gap-3 rounded-lg border border-emerald-300 bg-emerald-50 p-3 sm:flex-row sm:items-center sm:justify-between" aria-live="polite">
                                <div class="flex min-w-0 items-center gap-3">
                                    <img v-if="scanCandidate.image_url" :src="scanCandidate.image_url" :alt="`Imagen de ${scanCandidate.name}`" width="56" height="56" class="h-14 w-14 shrink-0 rounded-lg border border-emerald-200 bg-white object-contain p-1" />
                                    <span v-else class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg border border-emerald-200 bg-white text-slate-400" aria-label="Producto sin imagen"><i class="pi pi-image" aria-hidden="true" /></span>
                                    <span class="min-w-0"><b class="block truncate text-sm text-slate-900">{{ scanCandidate.name }}</b><span class="font-mono text-xs text-slate-600">{{ scanCandidate.sku || 'Sin SKU' }}</span><span class="mt-1 block text-xs font-semibold text-emerald-800">Acumulado: {{ scannedQuantity(scanCandidate.id) }} unidades</span></span>
                                </div>
                                <div v-if="countMode === 'quantity' && !scanCandidateAdded" class="flex items-end gap-2">
                                    <label class="text-xs font-semibold text-slate-700">Cantidad
                                        <InputNumber v-model="scanQuantity" input-class="w-24" :min="1" :max="MAX_TOTAL_UNITS" :use-grouping="false" :disabled="scanBusy || busy" />
                                    </label>
                                    <Button type="button" label="Agregar al borrador" icon="pi pi-plus" size="small" :disabled="scanBusy || busy || !isPositiveInteger(scanQuantity)" @click="addScanCandidate" />
                                </div>
                                <Tag v-else :value="countMode === 'unit' ? 'Lectura unitaria agregada' : 'Agregado al borrador'" severity="success" />
                            </div>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-slate-50 p-4" aria-labelledby="receipt-product-search-title">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <label class="min-w-0 flex-1 text-sm font-semibold text-slate-700" for="receipt-product-search">Buscar productos por nombre o SKU
                                    <InputText id="receipt-product-search" v-model="productSearch" class="mt-1 w-full" :disabled="busy || statusLoading || importBusy || lines.length >= MAX_LINES" placeholder="Escribe al menos 2 caracteres" @input="scheduleProductSearch" />
                                </label>
                                <span class="text-xs text-slate-600">{{ lines.length }}/{{ MAX_LINES }} productos · {{ totalUnits }}/{{ MAX_TOTAL_UNITS }} unidades</span>
                            </div>
                            <p id="receipt-product-search-title" class="mt-2 text-xs text-slate-500">Máximo {{ MAX_LINES }} productos y {{ MAX_TOTAL_UNITS.toLocaleString('es-PE') }} unidades por recepción.</p>
                            <div v-if="productLoading" class="py-3 text-sm text-slate-500" role="status">Buscando productos…</div>
                            <div v-else-if="productSearchError" class="py-3 text-sm text-red-700" role="alert">{{ productSearchError }}</div>
                            <ul v-else-if="productResults.length" class="mt-3 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white" aria-label="Resultados de productos">
                                <li v-for="product in productResults" :key="product.id" class="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
                                    <span class="min-w-0"><b class="block truncate text-sm text-slate-900">{{ product.name }}</b><span class="font-mono text-xs text-slate-500">{{ product.sku || 'Sin SKU' }}</span></span>
                                    <Button type="button" :label="isDuplicate(product.id) ? 'Ya agregado' : 'Agregar'" :icon="isDuplicate(product.id) ? 'pi pi-check' : 'pi pi-plus'" size="small" severity="secondary" outlined :disabled="busy || importBusy || isDuplicate(product.id) || lines.length >= MAX_LINES" @click="addProduct(product)" />
                                </li>
                            </ul>
                            <p v-else-if="productSearch.trim().length >= 2" class="mt-3 text-sm text-slate-500">No se encontraron productos activos.</p>
                            <p v-if="duplicateLineIds.length" class="mt-2 text-sm text-red-700" role="alert">Hay productos duplicados; retira las líneas repetidas antes de guardar.</p>
                        </section>

                        <section aria-labelledby="receipt-lines-title">
                            <div class="mb-2 flex items-center justify-between gap-3"><h4 id="receipt-lines-title" class="font-bold text-slate-900">Líneas de recepción</h4><span class="text-xs text-slate-500">Las cantidades deben ser enteros positivos.</span></div>
                            <div v-if="!lines.length" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">Busca y agrega los productos recibidos.</div>
                            <ul v-else class="space-y-2">
                                <li v-for="(line, index) in lines" :key="`${line.product_id}-${index}`" class="grid grid-cols-[minmax(0,1fr)_7rem_2.5rem] items-center gap-2 rounded-xl border border-slate-200 p-3">
                                    <span class="min-w-0"><b class="block truncate text-sm text-slate-900">{{ line.product?.name || 'Producto no disponible' }}</b><span class="font-mono text-xs text-slate-500">{{ line.product?.sku || 'Sin SKU' }}</span></span>
                                    <InputNumber v-model="line.quantity" :input-id="`receipt-quantity-${index}`" class="w-full" input-class="w-full" :min="1" :max="MAX_TOTAL_UNITS" :use-grouping="false" :disabled="busy || importBusy" :invalid="!isPositiveInteger(line.quantity)" aria-label="Cantidad recibida" />
                                    <Button type="button" icon="pi pi-trash" text rounded severity="danger" :aria-label="`Quitar ${line.product?.name || 'producto'} de la recepción`" :disabled="busy || importBusy" @click="removeLine(index)" />
                                </li>
                            </ul>
                            <p v-if="totalUnits > MAX_TOTAL_UNITS" class="mt-2 text-sm text-red-700" role="alert">El total supera el máximo de {{ MAX_TOTAL_UNITS.toLocaleString('es-PE') }} unidades.</p>
                            <p v-if="!allQuantitiesValid" class="mt-2 text-sm text-red-700" role="alert">Revisa las cantidades: deben ser enteros mayores que cero.</p>
                        </section>

                        <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
                            <Button label="Cerrar editor" severity="secondary" text :disabled="busy || importBusy" @click="closeEditor" />
                            <Button v-if="receipt?.status === 'draft'" label="Revisar y confirmar" icon="pi pi-check" severity="success" outlined :disabled="busy || importBusy || statusLoading || isDirty || !receipt.lines?.length" @click="openConfirmation" />
                            <Button label="Guardar borrador" icon="pi pi-save" :loading="busy && busyAction === 'save'" :disabled="!canSave || busy || importBusy || statusLoading || statusUncertain || ambiguousCreate" @click="saveDraft" />
                        </div>
                        <p v-if="receipt?.status === 'draft' && isDirty" class="text-right text-xs text-amber-700">Guarda los cambios para habilitar la confirmación del contenido vigente.</p>
                    </section>

                    <section v-else-if="receipt" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-labelledby="receipt-document-title">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div><p class="text-xs font-bold uppercase tracking-wider text-blue-700">Documento de recepción</p><h3 id="receipt-document-title" class="mt-1 text-xl font-black text-slate-900">{{ receipt.reference }}</h3></div>
                            <Tag :value="statusLabel(receipt.status)" :severity="statusSeverity(receipt.status)" />
                        </div>
                        <dl class="grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2">
                            <div><dt class="text-xs font-semibold uppercase text-slate-500">Almacén</dt><dd class="mt-1 font-bold text-slate-900">{{ receipt.warehouse?.name || 'Almacén no disponible' }} <span v-if="receipt.warehouse?.code" class="font-mono text-xs text-slate-500">({{ receipt.warehouse.code }})</span></dd></div>
                            <div><dt class="text-xs font-semibold uppercase text-slate-500">Unidades</dt><dd class="mt-1 font-bold text-slate-900">{{ units(receipt.lines) }}</dd></div>
                            <div v-if="receipt.external_reference"><dt class="text-xs font-semibold uppercase text-slate-500">Referencia externa</dt><dd class="mt-1 text-slate-900">{{ receipt.external_reference }}</dd></div>
                            <div v-if="receipt.confirmed_at"><dt class="text-xs font-semibold uppercase text-slate-500">Confirmada por</dt><dd class="mt-1 text-slate-900">{{ receipt.confirmed_by ? `Usuario #${receipt.confirmed_by}` : 'Usuario no disponible' }}</dd></div>
                            <div v-if="receipt.confirmed_at"><dt class="text-xs font-semibold uppercase text-slate-500">Fecha de confirmación</dt><dd class="mt-1 text-slate-900">{{ formatDate(receipt.confirmed_at) }}</dd></div>
                        </dl>
                        <div v-if="receipt.notes" class="text-sm text-slate-700"><b>Notas:</b> {{ receipt.notes }}</div>
                        <div class="overflow-hidden rounded-xl border border-slate-200">
                            <div class="border-b border-slate-100 bg-slate-50 px-4 py-3 font-bold text-slate-800">Productos recibidos</div>
                            <ul class="divide-y divide-slate-100">
                                <li v-for="line in receipt.lines || []" :key="line.id || line.product_id" class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                    <span class="min-w-0"><b class="block truncate text-slate-900">{{ line.product?.name || 'Producto no disponible' }}</b><span class="font-mono text-xs text-slate-500">{{ line.product?.sku || 'Sin SKU' }}</span></span>
                                    <span class="shrink-0 font-bold text-emerald-700">+{{ line.quantity }}</span>
                                </li>
                            </ul>
                        </div>
                        <div v-if="receipt.status === 'confirmed'" class="flex flex-col gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950 sm:flex-row sm:items-center sm:justify-between" role="status">
                            <p><b>Recepción confirmada.</b> Los saldos y el historial de movimientos se actualizaron.</p>
                            <Button label="Ver historial de movimientos" icon="pi pi-list" severity="success" outlined @click="$emit('show-history')" />
                        </div>
                        <div v-else-if="receipt.status === 'cancelled'" class="rounded-xl bg-slate-100 p-4 text-sm text-slate-700">Este borrador fue cancelado y es de solo lectura.</div>
                        <div v-else class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
                            <Button label="Cancelar borrador" severity="secondary" outlined :disabled="busy || statusLoading" @click="cancelDraft" />
                            <Button label="Confirmar recepción" icon="pi pi-check" :disabled="busy || statusLoading || statusUncertain || !receipt.lines?.length" @click="openConfirmation" />
                        </div>
                    </section>
                </template>
            </main>
        </div>

        <Dialog v-model:visible="confirmationOpen" modal header="Confirmar recepción masiva" :style="{ width: 'min(36rem, 95vw)' }" :closable="!busy" :close-on-escape="!busy">
            <div v-if="receipt" class="space-y-4">
                <p class="text-sm text-slate-700">Verifica el resumen antes de ingresar las unidades al inventario. Después de confirmar, el documento será de solo lectura.</p>
                <dl class="rounded-xl bg-slate-50 p-4 text-sm"><div class="flex justify-between gap-3"><dt class="text-slate-500">Almacén</dt><dd class="text-right font-bold">{{ receipt.warehouse?.name }}</dd></div><div class="mt-2 flex justify-between gap-3"><dt class="text-slate-500">Productos</dt><dd class="font-bold">{{ receipt.lines?.length || 0 }}</dd></div><div class="mt-2 flex justify-between gap-3"><dt class="text-slate-500">Unidades</dt><dd class="font-bold">{{ units(receipt.lines) }}</dd></div></dl>
                <ul class="max-h-56 divide-y divide-slate-200 overflow-y-auto rounded-xl border border-slate-200 text-sm">
                    <li v-for="line in receipt.lines || []" :key="line.id || line.product_id" class="flex justify-between gap-3 p-3"><span class="min-w-0 truncate">{{ line.product?.name || 'Producto no disponible' }}</span><b class="shrink-0">{{ line.quantity }} un.</b></li>
                </ul>
                <p v-if="receipt.external_reference" class="text-xs text-slate-500">Referencia: {{ receipt.external_reference }}</p>
            </div>
            <template #footer>
                <Button label="Volver a revisar" severity="secondary" text :disabled="busy" @click="confirmationOpen = false" />
                <Button label="Confirmar ingreso" icon="pi pi-check" :loading="busy && busyAction === 'confirm'" :disabled="busy || statusLoading" @click="confirmReceipt" />
            </template>
        </Dialog>
    </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';
import QrScanner from 'qr-scanner';
import { accumulateReceiptScan } from '@/utils/inventoryReceiptScan.mjs';
import AdminPagination from '@/components/admin/AdminPagination.vue';
import api from '@/api';

const MAX_LINES = 100;
const MAX_TOTAL_UNITS = 100000;
const toast = useToast();
const receipts = ref([]);
const meta = ref({});
const listLoading = ref(false);
const pageError = ref('');
const warehouses = ref([]);
const receipt = ref(null);
const creatingNew = ref(false);
const form = ref(emptyForm());
const lines = ref([]);
const productSearch = ref('');
const productResults = ref([]);
const productLoading = ref(false);
const productSearchError = ref('');
const busy = ref(false);
const busyAction = ref('');
const statusLoading = ref(false);
const statusUncertain = ref(false);
const conflictMessage = ref('');
const recoveryMessage = ref('');
const retrySaveAvailable = ref(false);
const retryConfirmAvailable = ref(false);
const ambiguousCreate = ref(false);
const ambiguousCreateListChecked = ref(false);
const confirmationOpen = ref(false);
const importFileInput = ref(null);
const importFile = ref(null);
const importPreview = ref(null);
const importBusy = ref(false);
const importError = ref('');
const countMode = ref('quantity');
const countModeOptions = [
    { label: 'Ingresar cantidad', value: 'quantity' },
    { label: 'Contar de a una unidad', value: 'unit' },
];
const scanCode = ref('');
const scanQuantity = ref(1);
const scanCandidate = ref(null);
const scanCandidateAdded = ref(false);
const scanBusy = ref(false);
const scanError = ref(false);
const scanMessage = ref('');
const cameraOpen = ref(false);
const cameraPaused = ref(false);
const scannerStarting = ref(false);
const cameraMessage = ref('');
const cameraVideo = ref(null);
const cameraList = ref([]);
const selectedCameraId = ref('');
let qrScanner = null;
let productSearchTimer = null;
let productRequestSequence = 0;
let listRequestSequence = 0;

const warehouseOptions = computed(() => {
    const options = warehouses.value.map(warehouse => ({
        ...warehouse,
        label: `${warehouse.branch?.name ? `${warehouse.branch.name} · ` : ''}${warehouse.code} · ${warehouse.name}`,
        disabled: false,
    }));
    const currentWarehouse = receipt.value?.warehouse;
    if (currentWarehouse && !options.some(option => Number(option.id) === Number(currentWarehouse.id))) {
        options.unshift({ ...currentWarehouse, label: `${currentWarehouse.code} · ${currentWarehouse.name} (inactivo)`, disabled: true });
    }
    return options;
});
const selectedWarehouseLabel = computed(() => warehouseOptions.value.find(option => Number(option.id) === Number(form.value.warehouse_id))?.name || 'Selecciona un almacén');
const totalUnits = computed(() => lines.value.reduce((sum, line) => sum + (Number.isFinite(Number(line.quantity)) ? Number(line.quantity) : 0), 0));
const duplicateLineIds = computed(() => lines.value.map(line => Number(line.product_id)).filter((id, index, all) => all.indexOf(id) !== index));
const allQuantitiesValid = computed(() => lines.value.every(line => isPositiveInteger(line.quantity)));
const isDirty = computed(() => {
    if (creatingNew.value || !receipt.value) return false;
    const currentLines = lines.value.map(line => ({ product_id: Number(line.product_id), quantity: Number(line.quantity) }));
    const savedLines = (receipt.value.lines || []).map(line => ({ product_id: Number(line.product_id), quantity: Number(line.quantity) }));
    return Number(form.value.warehouse_id) !== Number(receipt.value.warehouse?.id ?? receipt.value.warehouse_id)
        || (form.value.external_reference?.trim() || null) !== (receipt.value.external_reference || null)
        || (form.value.notes?.trim() || null) !== (receipt.value.notes || null)
        || JSON.stringify(currentLines) !== JSON.stringify(savedLines);
});
const canSave = computed(() => Boolean(form.value.warehouse_id)
    && lines.value.length > 0
    && lines.value.length <= MAX_LINES
    && totalUnits.value <= MAX_TOTAL_UNITS
    && allQuantitiesValid.value
    && duplicateLineIds.value.length === 0
    && warehouses.value.some(warehouse => Number(warehouse.id) === Number(form.value.warehouse_id)));

watch(() => Number(form.value.warehouse_id), clearImportPreview);
watch(lines, clearImportPreview, { deep: true });
watch(countMode, () => {
    scanCandidate.value = null;
    scanMessage.value = '';
    scanError.value = false;
});
watch([creatingNew, () => receipt.value?.status], ([isNew, status]) => {
    if (!isNew && status && status !== 'draft') closeCamera();
});

function emptyForm() {
    return { warehouse_id: null, external_reference: '', notes: '' };
}

function unwrap(response) {
    return response?.data?.data ?? response?.data ?? response;
}

function errorMessage(error, fallback) {
    const validation = error?.response?.data?.errors;
    const firstValidationMessage = validation && Object.values(validation).flat()?.[0];
    return firstValidationMessage || error?.response?.data?.message || fallback;
}

function statusLabel(status) {
    return ({ draft: 'Borrador', confirmed: 'Confirmada', cancelled: 'Cancelada' })[status] || 'Estado desconocido';
}

function statusSeverity(status) {
    return ({ draft: 'warn', confirmed: 'success', cancelled: 'secondary' })[status] || 'secondary';
}

function units(items = []) {
    return (items || []).reduce((sum, line) => sum + (Number(line.quantity) || 0), 0);
}

function formatDate(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Fecha no disponible' : date.toLocaleString('es-PE');
}

function isPositiveInteger(value) {
    return Number.isSafeInteger(Number(value)) && Number(value) > 0;
}

function isDuplicate(productId) {
    return lines.value.some(line => Number(line.product_id) === Number(productId));
}

async function loadReceipts(page = 1) {
    const requestId = ++listRequestSequence;
    listLoading.value = true;
    pageError.value = '';
    try {
        const { data } = await api.get('/admin/inventory-receipts', { params: { page } });
        if (requestId !== listRequestSequence) return;
        const body = data || {};
        receipts.value = Array.isArray(body.data) ? body.data : [];
        meta.value = body.meta || body;
        if (ambiguousCreate.value && Number(page) === 1) ambiguousCreateListChecked.value = true;
    } catch (error) {
        if (requestId === listRequestSequence) pageError.value = errorMessage(error, 'No se pudo cargar la lista de recepciones.');
    } finally {
        if (requestId === listRequestSequence) listLoading.value = false;
    }
}

async function loadWarehouses() {
    try {
        const { data } = await api.get('/admin/warehouses/options');
        warehouses.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
    } catch (error) {
        pageError.value = errorMessage(error, 'No se pudieron cargar los almacenes activos.');
    }
}

async function openReceipt(id) {
    closeCamera();
    creatingNew.value = false;
    receipt.value = null;
    conflictMessage.value = '';
    recoveryMessage.value = '';
    retrySaveAvailable.value = false;
    retryConfirmAvailable.value = false;
    statusUncertain.value = false;
    ambiguousCreate.value = false;
    ambiguousCreateListChecked.value = false;
    pageError.value = '';
    statusLoading.value = true;
    try {
        const { data } = await api.get(`/admin/inventory-receipts/${id}`);
        applyReceipt(unwrap({ data }));
    } catch (error) {
        pageError.value = errorMessage(error, 'No se pudo abrir esta recepción.');
    } finally {
        statusLoading.value = false;
    }
}

function applyReceipt(data) {
    const current = data?.data ?? data;
    receipt.value = current;
    creatingNew.value = false;
    form.value = {
        warehouse_id: current?.warehouse?.id ?? current?.warehouse_id ?? null,
        external_reference: current?.external_reference || '',
        notes: current?.notes || '',
    };
    lines.value = (current?.lines || []).map(line => ({
        id: line.id,
        product_id: Number(line.product_id),
        product: line.product || null,
        quantity: Number(line.quantity),
        sort_order: Number(line.sort_order || 0),
    }));
    productSearch.value = '';
    productResults.value = [];
    conflictMessage.value = '';
    retrySaveAvailable.value = false;
    retryConfirmAvailable.value = false;
    statusUncertain.value = false;
    recoveryMessage.value = '';
    confirmationOpen.value = false;
}

function startNewReceipt() {
    if (ambiguousCreate.value) return;
    closeCamera();
    receipt.value = null;
    creatingNew.value = true;
    form.value = emptyForm();
    lines.value = [];
    productSearch.value = '';
    productResults.value = [];
    conflictMessage.value = '';
    recoveryMessage.value = '';
    retrySaveAvailable.value = false;
    retryConfirmAvailable.value = false;
    statusUncertain.value = false;
    confirmationOpen.value = false;
}

function allowCreateRetry() {
    if (!ambiguousCreate.value || !ambiguousCreateListChecked.value || listLoading.value) return;
    ambiguousCreate.value = false;
    ambiguousCreateListChecked.value = false;
    recoveryMessage.value = '';
}

function closeEditor() {
    closeCamera();
    creatingNew.value = false;
    receipt.value = null;
    lines.value = [];
    conflictMessage.value = '';
    recoveryMessage.value = '';
}

function scheduleProductSearch() {
    clearTimeout(productSearchTimer);
    productSearchError.value = '';
    productResults.value = [];
    const query = productSearch.value.trim();
    if (query.length < 2) {
        productLoading.value = false;
        productRequestSequence++;
        return;
    }
    productSearchTimer = setTimeout(() => searchProducts(query), 250);
}

async function searchProducts(query) {
    const requestId = ++productRequestSequence;
    productLoading.value = true;
    productSearchError.value = '';
    try {
        const { data } = await api.get('/admin/products', { params: { search: query, is_active: '1', per_page: '15', page: '1', sort: 'name', direction: 'asc' } });
        if (requestId !== productRequestSequence || query !== productSearch.value.trim()) return;
        productResults.value = Array.isArray(data?.data) ? data.data : [];
    } catch (error) {
        if (requestId === productRequestSequence) productSearchError.value = errorMessage(error, 'No se pudo buscar productos.');
    } finally {
        if (requestId === productRequestSequence) productLoading.value = false;
    }
}

function addProduct(product) {
    if (busy.value || isDuplicate(product.id) || lines.value.length >= MAX_LINES) return;
    lines.value.push({ product_id: Number(product.id), product, quantity: 1, sort_order: lines.value.length });
}

async function submitScanCode() {
    const code = scanCode.value.trim();
    scanCode.value = '';
    if (!code || scanBusy.value || busy.value || statusLoading.value || !form.value.warehouse_id) return;
    await resolveScannedCode(code);
}

async function resolveScannedCode(code) {
    if (scanBusy.value || !form.value.warehouse_id || busy.value) return;
    scanBusy.value = true;
    scanError.value = false;
    scanMessage.value = '';
    scanCandidate.value = null;
    try {
        const { data } = await api.post('/admin/inventory-receipts/resolve-product-code', { code });
        const product = data?.data;
        if (!product || !Number.isSafeInteger(Number(product.id)) || typeof product.name !== 'string') {
            throw new Error('invalid_product_projection');
        }
        const normalized = { id: Number(product.id), name: product.name, sku: product.sku || null, image_url: product.image_url || null };
        if (countMode.value === 'unit') {
            if (addScannedProduct(normalized, 1)) {
                scanCandidate.value = normalized;
                scanCandidateAdded.value = true;
                scanMessage.value = `${normalized.name}: acumulado ${scannedQuantity(normalized.id)}. Reanuda el lector para otra lectura.`;
            }
        } else {
            scanCandidate.value = normalized;
            scanCandidateAdded.value = false;
            scanQuantity.value = 1;
            scanMessage.value = 'Producto identificado. Revisa la cantidad y agrégalo al borrador.';
        }
    } catch (error) {
        scanError.value = true;
        scanMessage.value = error?.response?.status === 429
            ? 'Se alcanzó el límite de lecturas. Espera un momento e inténtalo nuevamente.'
            : error?.response?.status === 404
                ? 'No se encontró un producto activo para ese código.'
                : error?.response?.status === 422
                    ? 'El código no tiene un formato válido.'
                    : 'No se pudo identificar el producto. Revisa el código e inténtalo nuevamente.';
    } finally {
        scanBusy.value = false;
    }
}

function addScanCandidate() {
    const product = scanCandidate.value;
    if (!product || scanBusy.value || !isPositiveInteger(scanQuantity.value)) return;
    if (addScannedProduct(product, Number(scanQuantity.value))) {
        scanCandidateAdded.value = true;
        scanMessage.value = `${product.name}: se agregó al borrador. Guarda la recepción para conservar el cambio.`;
    }
}

function addScannedProduct(product, quantity) {
    const result = accumulateReceiptScan(lines.value, product, quantity, { maxLines: MAX_LINES, maxTotalUnits: MAX_TOTAL_UNITS });
    if (!result.ok) {
        scanError.value = true;
        scanMessage.value = result.reason === 'max_lines'
            ? `La recepción ya alcanzó el máximo de ${MAX_LINES} productos.`
            : `La lectura supera el máximo de ${MAX_TOTAL_UNITS.toLocaleString('es-PE')} unidades por recepción.`;
        return false;
    }
    lines.value = result.lines;
    scanError.value = false;
    return true;
}

function scannedQuantity(productId) {
    return Number(lines.value.find(line => Number(line.product_id) === Number(productId))?.quantity || 0);
}

async function openCamera() {
    if (cameraOpen.value || !form.value.warehouse_id || busy.value || importBusy.value) return;
    cameraMessage.value = '';
    try {
        if (!await QrScanner.hasCamera()) {
            cameraMessage.value = 'Este dispositivo no tiene una cámara disponible. Puedes usar el lector USB/Bluetooth o escribir el SKU.';
            return;
        }
        cameraOpen.value = true;
        cameraPaused.value = false;
        await nextTick();
        if (!cameraVideo.value) throw new Error('video_unavailable');
        qrScanner = new QrScanner(cameraVideo.value, result => handleCameraScan(result.data), {
            preferredCamera: 'environment',
            maxScansPerSecond: 8,
            returnDetailedScanResult: true,
            onDecodeError: () => {},
        });
        scannerStarting.value = true;
        await qrScanner.start();
        cameraList.value = await QrScanner.listCameras();
        selectedCameraId.value = cameraList.value.find(camera => camera.id)?.id || '';
    } catch (error) {
        closeCamera();
        cameraMessage.value = error?.name === 'NotAllowedError'
            ? 'No se concedió permiso para usar la cámara. Habilítalo o usa el lector manual.'
            : 'No se pudo iniciar la cámara. Verifica el permiso y que la página use HTTPS o localhost.';
    } finally {
        scannerStarting.value = false;
    }
}

async function handleCameraScan(code) {
    if (cameraPaused.value || scanBusy.value || !cameraOpen.value) return;
    cameraPaused.value = true;
    await qrScanner?.pause();
    await resolveScannedCode(code);
}

async function resumeCamera() {
    if (!qrScanner || !cameraOpen.value || scannerStarting.value || scanBusy.value) return;
    cameraPaused.value = false;
    cameraMessage.value = '';
    try {
        await qrScanner.start();
    } catch {
        cameraPaused.value = true;
        cameraMessage.value = 'No se pudo reanudar la cámara. Ciérrala y vuelve a abrirla.';
    }
}

async function pauseCamera() {
    if (!qrScanner || cameraPaused.value) return;
    cameraPaused.value = true;
    await qrScanner.pause();
}

async function changeCamera(cameraId) {
    if (!qrScanner || !cameraId) return;
    try {
        await qrScanner.setCamera(cameraId);
    } catch {
        cameraMessage.value = 'No se pudo cambiar de cámara.';
    }
}

function closeCamera() {
    cameraOpen.value = false;
    cameraPaused.value = false;
    scannerStarting.value = false;
    cameraList.value = [];
    selectedCameraId.value = '';
    if (qrScanner) {
        qrScanner.stop();
        qrScanner.destroy();
        qrScanner = null;
    }
}

function removeLine(index) {
    lines.value.splice(index, 1);
    lines.value.forEach((line, sortOrder) => { line.sort_order = sortOrder; });
}

function payload() {
    return {
        warehouse_id: Number(form.value.warehouse_id),
        external_reference: form.value.external_reference?.trim() || null,
        notes: form.value.notes?.trim() || null,
        lines: lines.value.map(line => ({ product_id: Number(line.product_id), quantity: Number(line.quantity) })),
    };
}

function clearImportPreview() {
    importPreview.value = null;
    importError.value = '';
}

function openImportFilePicker() {
    importFileInput.value?.click();
}

function onImportFileChange(event) {
    importFile.value = event.target.files?.[0] || null;
    event.target.value = '';
    importPreview.value = null;
    importError.value = '';
}

function formatFileSize(bytes) {
    if (!Number.isFinite(Number(bytes)) || Number(bytes) < 0) return 'tamaño desconocido';
    return Number(bytes) < 1024 * 1024
        ? `${Math.max(1, Math.round(Number(bytes) / 1024))} KB`
        : `${(Number(bytes) / (1024 * 1024)).toFixed(1)} MB`;
}

async function downloadImportTemplate() {
    try {
        const { data } = await api.get('/admin/inventory-receipts/import-template', { responseType: 'blob' });
        const url = URL.createObjectURL(data);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'plantilla-recepcion-inventario.csv';
        anchor.click();
        setTimeout(() => URL.revokeObjectURL(url), 0);
    } catch (error) {
        importError.value = errorMessage(error, 'No se pudo descargar la plantilla.');
    }
}

async function previewImport() {
    if (!importFile.value || !form.value.warehouse_id || importBusy.value || busy.value || statusLoading.value) return;
    const formData = new FormData();
    formData.append('warehouse_id', String(form.value.warehouse_id));
    formData.append('file', importFile.value);
    formData.append('existing_lines', JSON.stringify(lines.value.map(line => ({
        product_id: Number(line.product_id),
        quantity: Number(line.quantity),
    }))));
    importBusy.value = true;
    importError.value = '';
    importPreview.value = null;
    try {
        const { data } = await api.post('/admin/inventory-receipts/import-preview', formData);
        importPreview.value = data?.data ?? data;
    } catch (error) {
        const body = error?.response?.data;
        if (body && (Array.isArray(body.rows) || Array.isArray(body.global_errors))) {
            importPreview.value = body;
        } else {
            importError.value = errorMessage(error, 'No se pudo validar el archivo.');
        }
    } finally {
        importBusy.value = false;
    }
}

function appendImportedRows() {
    const preview = importPreview.value;
    if (!preview?.can_import || busy.value || importBusy.value) return;
    const imported = preview.rows.map((row, index) => ({
        product_id: Number(row.product_id),
        product: { id: Number(row.product_id), name: row.product_name, sku: row.sku },
        quantity: Number(row.quantity),
        sort_order: lines.value.length + index,
    }));
    lines.value.push(...imported);
    importFile.value = null;
    importPreview.value = null;
    importError.value = '';
    if (importFileInput.value) importFileInput.value.value = '';
    toast.add({ severity: 'success', summary: 'Filas incorporadas', detail: 'Revisa las líneas y guarda el borrador para conservar los cambios.', life: 4000 });
}

async function saveDraft() {
    if (busy.value || statusUncertain.value || !canSave.value || ambiguousCreate.value) return;
    const isNew = creatingNew.value;
    const receiptId = receipt.value?.id;
    const requestPayload = payload();
    busy.value = true;
    busyAction.value = 'save';
    recoveryMessage.value = '';
    retrySaveAvailable.value = false;
    try {
        const { data } = isNew
            ? await api.post('/admin/inventory-receipts', requestPayload)
            : await api.put(`/admin/inventory-receipts/${receiptId}`, { ...requestPayload, version: receipt.value.version });
        applyReceipt(unwrap({ data }));
        await loadReceipts(1);
        toast.add({ severity: 'success', summary: 'Borrador guardado', detail: 'La recepción quedó guardada y aún no modifica existencias.', life: 3500 });
    } catch (error) {
        if (error?.response?.status === 409 && !isNew) {
            conflictMessage.value = 'Este borrador cambió desde que lo abriste. Carga la versión vigente para revisarla; no se sobrescribieron tus cambios.';
        } else if (isNetworkError(error)) {
            if (isNew) {
                ambiguousCreate.value = true;
                ambiguousCreateListChecked.value = false;
                recoveryMessage.value = 'La respuesta al crear el borrador se perdió. Actualiza la lista para localizarlo; no se enviará otro borrador automáticamente.';
                await loadReceipts(1);
            } else {
                await recoverReceiptState('save', requestPayload, receiptId);
            }
        } else {
            toast.add({ severity: 'error', summary: 'No se pudo guardar', detail: errorMessage(error, 'Revisa los datos de la recepción.'), life: 5000 });
        }
    } finally {
        busy.value = false;
        busyAction.value = '';
    }
}

function isNetworkError(error) {
    return !error?.response || ['ERR_NETWORK', 'ECONNABORTED', 'ETIMEDOUT'].includes(error?.code);
}

async function recoverReceiptState(action, requestPayload = null, receiptId = receipt.value?.id) {
    if (!receiptId || statusLoading.value) return;
    statusLoading.value = true;
    statusUncertain.value = true;
    retrySaveAvailable.value = false;
    retryConfirmAvailable.value = false;
    recoveryMessage.value = 'Consultando el estado de esta misma recepción…';
    try {
        const { data } = await api.get(`/admin/inventory-receipts/${receiptId}`);
        const current = unwrap({ data });
        applyReceipt(current);
        if (action === 'confirm') {
            if (current.status === 'confirmed') {
                toast.add({ severity: 'success', summary: 'Recepción confirmada', detail: 'La respuesta anterior se perdió, pero la API confirma que el documento ya fue procesado.', life: 5000 });
                emitConfirmed();
            } else if (current.status === 'draft') {
                statusUncertain.value = false;
                retryConfirmAvailable.value = true;
                recoveryMessage.value = 'La recepción sigue como borrador. Puedes reintentar la confirmación de este mismo documento.';
            }
        } else if (action === 'save') {
            const applied = sameDraft(current, requestPayload);
            if (applied) {
                toast.add({ severity: 'success', summary: 'Borrador guardado', detail: 'La API confirma que los cambios quedaron guardados.', life: 4000 });
            } else if (current.status === 'draft') {
                statusUncertain.value = false;
                retrySaveAvailable.value = true;
                recoveryMessage.value = 'La recepción continúa como borrador. Revisa la versión cargada y, si corresponde, vuelve a guardar este mismo documento.';
            }
        }
        await loadReceipts(meta.value.current_page || 1);
    } catch {
        recoveryMessage.value = 'No se pudo consultar el estado del documento. No reintentes hasta poder verificar esta recepción.';
    } finally {
        statusLoading.value = false;
        if (recoveryMessage.value.startsWith('Consultando')) {
            statusUncertain.value = true;
        }
    }
}

function sameDraft(current, requestPayload) {
    if (!current || current.status !== 'draft' || !requestPayload) return false;
    const expected = [...requestPayload.lines].sort((a, b) => a.product_id - b.product_id);
    const actual = [...(current.lines || [])].map(line => ({ product_id: Number(line.product_id), quantity: Number(line.quantity) })).sort((a, b) => a.product_id - b.product_id);
    return Number(current.warehouse?.id ?? current.warehouse_id) === requestPayload.warehouse_id
        && (current.external_reference || null) === requestPayload.external_reference
        && (current.notes || null) === requestPayload.notes
        && JSON.stringify(actual) === JSON.stringify(expected);
}

async function loadCurrentVersion() {
    if (!receipt.value?.id || statusLoading.value) return;
    statusLoading.value = true;
    try {
        const { data } = await api.get(`/admin/inventory-receipts/${receipt.value.id}`);
        applyReceipt(unwrap({ data }));
        await loadReceipts(meta.value.current_page || 1);
        toast.add({ severity: 'info', summary: 'Versión vigente cargada', detail: 'Revisa el contenido actualizado antes de guardar o confirmar.', life: 3500 });
    } catch (error) {
        pageError.value = errorMessage(error, 'No se pudo cargar la versión vigente.');
    } finally {
        statusLoading.value = false;
    }
}

function openConfirmation() {
    if (busy.value || statusLoading.value || receipt.value?.status !== 'draft') return;
    confirmationOpen.value = true;
}

async function confirmReceipt() {
    if (busy.value || statusLoading.value || statusUncertain.value || receipt.value?.status !== 'draft') return;
    const receiptId = receipt.value.id;
    busy.value = true;
    busyAction.value = 'confirm';
    retryConfirmAvailable.value = false;
    recoveryMessage.value = '';
    try {
        const { data } = await api.post(`/admin/inventory-receipts/${receiptId}/confirm`, { version: receipt.value.version });
        applyReceipt(unwrap({ data }));
        await loadReceipts(meta.value.current_page || 1);
        emitConfirmed();
        toast.add({ severity: 'success', summary: 'Recepción confirmada', detail: 'Los saldos y el historial reflejan las unidades recibidas.', life: 4500 });
    } catch (error) {
        confirmationOpen.value = false;
        if (error?.response?.status === 409) {
            conflictMessage.value = 'La recepción cambió o ya fue procesada. Consulta la versión vigente antes de realizar otra acción.';
        } else if (isNetworkError(error)) {
            await recoverReceiptState('confirm', null, receiptId);
        } else {
            toast.add({ severity: 'error', summary: 'No se pudo confirmar', detail: errorMessage(error, 'La recepción no fue confirmada.'), life: 5000 });
        }
    } finally {
        busy.value = false;
        busyAction.value = '';
    }
}

async function cancelDraft() {
    if (busy.value || statusLoading.value || receipt.value?.status !== 'draft') return;
    if (!window.confirm('¿Cancelar este borrador? No se modificarán las existencias.')) return;
    busy.value = true;
    busyAction.value = 'cancel';
    try {
        const { data } = await api.post(`/admin/inventory-receipts/${receipt.value.id}/cancel`, { version: receipt.value.version });
        applyReceipt(unwrap({ data }));
        await loadReceipts(meta.value.current_page || 1);
        toast.add({ severity: 'success', summary: 'Borrador cancelado', life: 3000 });
    } catch (error) {
        if (error?.response?.status === 409) conflictMessage.value = 'El borrador cambió. Carga la versión vigente antes de volver a intentarlo.';
        else toast.add({ severity: 'error', summary: 'No se pudo cancelar', detail: errorMessage(error, 'No se canceló el borrador.'), life: 4500 });
    } finally {
        busy.value = false;
        busyAction.value = '';
    }
}

function emitConfirmed() {
    // Parent reloads both inventory balances and movement history.
    emit('confirmed');
}

const emit = defineEmits(['close', 'confirmed', 'show-history']);

onMounted(async () => {
    await Promise.all([loadWarehouses(), loadReceipts(1)]);
});

onBeforeUnmount(() => {
    clearTimeout(productSearchTimer);
    productRequestSequence++;
    listRequestSequence++;
    closeCamera();
});
</script>
