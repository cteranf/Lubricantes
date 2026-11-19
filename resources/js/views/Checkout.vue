<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-8">
            <h1 class="mb-8 text-center text-3xl font-bold">Finalizar pedido</h1>
            <div class="mx-auto grid max-w-5xl overflow-hidden rounded-xl bg-white shadow-lg lg:grid-cols-[1fr_22rem]">
                <form class="space-y-8 p-5 md:p-8" @submit.prevent="submitOrder">
                    <section>
                        <h2 class="mb-4 text-lg font-bold">Modalidad de entrega</h2>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <button type="button" class="min-h-16 rounded-xl border p-4 text-left" :class="form.delivery_type === 'delivery' ? 'border-blue-600 bg-blue-50 text-blue-800' : 'border-slate-200'" @click="selectDelivery('delivery')"><i class="pi pi-truck mr-2"></i><b>Envío a domicilio</b></button>
                            <button type="button" class="min-h-16 rounded-xl border p-4 text-left disabled:cursor-not-allowed disabled:opacity-50" :class="form.delivery_type === 'pickup' ? 'border-blue-600 bg-blue-50 text-blue-800' : 'border-slate-200'" :disabled="!pickupAvailable" aria-describedby="pickup-availability-message" @click="selectDelivery('pickup')"><i class="pi pi-map-marker mr-2"></i><b>Recojo en sede</b></button>
                        </div>
                        <div id="pickup-availability-message" class="mt-3 text-sm" aria-live="polite">
                            <p v-if="loadingPickupBranches" class="text-slate-500"><i class="pi pi-spin pi-spinner mr-2"></i>Consultando sedes de recojo…</p>
                            <div v-else-if="pickupBranchesError" class="flex flex-wrap items-center gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-red-800"><span>No pudimos consultar las sedes de recojo. Intenta nuevamente.</span><button type="button" class="min-h-10 font-bold underline" @click="loadPickupBranches">Reintentar</button></div>
                            <p v-else-if="pickupBranchesLoaded && !pickupBranches.length" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900">No hay sedes disponibles para recojo en este momento.</p>
                        </div>
                    </section>

                    <section v-if="form.delivery_type === 'delivery'" class="space-y-4">
                        <div><h2 class="text-lg font-bold">Dirección de entrega</h2><p class="text-sm text-slate-500">El costo se calcula con el distrito de una dirección guardada.</p></div>
                        <div v-if="addressesLoading" class="rounded-lg bg-slate-50 p-4 text-center text-slate-500">Cargando direcciones…</div>
                        <div v-else class="grid gap-3"><label v-for="address in addresses" :key="address.id" class="cursor-pointer rounded-xl border p-4" :class="form.address_id === address.id ? 'border-blue-600 bg-blue-50' : 'border-slate-200'"><div class="flex gap-3"><input v-model="form.address_id" type="radio" :value="address.id" class="mt-1 h-5 w-5"><div><b>{{ address.label || address.district }}</b><p class="text-sm">{{ address.address }} — {{ address.district }}, {{ address.province }}</p><p class="text-xs text-slate-500">{{ address.recipient_name }} · {{ address.phone }}</p></div></div></label></div>
                        <div v-if="legacyConfirmationRequired" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-950"><b>Direcciones guardadas en la versión anterior</b><p class="mt-1 text-sm">Por seguridad no se migraron automáticamente. Revisa y completa una antes de usarla.</p><div v-for="(address, index) in legacyAddresses" :key="index" class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-amber-200 pt-3"><span class="text-sm">{{ address.label || address.address || `Dirección anterior ${index + 1}` }}</span><button type="button" class="min-h-11 font-bold text-blue-800 underline" @click="reviewLegacyAddress(address)">Revisar y guardar</button></div><p v-if="legacyUnrecognizedCount" class="mt-3 border-t border-amber-200 pt-3 text-sm">{{ legacyUnrecognizedCount }} dirección(es) anterior(es) no tienen estructura suficiente para precargarse. Regístralas nuevamente sin eliminar el dato original.</p></div>
                        <button type="button" class="min-h-11 font-bold text-blue-700" @click="showAddressForm = !showAddressForm"><i class="pi pi-plus mr-2"></i>{{ showAddressForm ? 'Cancelar nueva dirección' : 'Registrar nueva dirección' }}</button>
                        <div v-if="showAddressForm" class="space-y-3 rounded-xl border bg-slate-50 p-4">
                            <div class="grid gap-3 sm:grid-cols-2"><label class="text-sm font-medium">Etiqueta<input v-model.trim="addressForm.label" placeholder="Casa, oficina…" class="mt-1 min-h-11 w-full rounded-lg border px-3"></label><label class="text-sm font-medium">Destinatario<input v-model.trim="addressForm.recipient_name" required class="mt-1 min-h-11 w-full rounded-lg border px-3"></label></div>
                            <label class="block text-sm font-medium">Dirección completa<input v-model.trim="addressForm.address" required class="mt-1 min-h-11 w-full rounded-lg border px-3"></label>
                            <label class="block text-sm font-medium">Referencia<input v-model.trim="addressForm.reference" class="mt-1 min-h-11 w-full rounded-lg border px-3"></label>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="text-sm font-medium">Departamento<select v-model="addressForm.department_id" required class="mt-1 min-h-11 w-full rounded-lg border px-3"><option :value="null" disabled>Selecciona</option><option v-for="item in departments" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                                <label class="text-sm font-medium">Provincia<select v-model="addressForm.province_id" required :disabled="territoryLoading.provinces || !addressForm.department_id" class="mt-1 min-h-11 w-full rounded-lg border px-3 disabled:bg-slate-100"><option :value="null" disabled>{{ territoryLoading.provinces ? 'Cargando…' : 'Selecciona' }}</option><option v-for="item in provinces" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                                <label class="text-sm font-medium">Distrito<select v-model="addressForm.district_id" required :disabled="territoryLoading.districts || !addressForm.province_id" class="mt-1 min-h-11 w-full rounded-lg border px-3 disabled:bg-slate-100"><option :value="null" disabled>{{ territoryLoading.districts ? 'Cargando…' : 'Selecciona' }}</option><option v-for="item in districts" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
                            </div>
                            <p v-if="addressForm.department_id && !territoryLoading.provinces && !provinces.length" class="text-sm text-amber-700">No hay provincias activas para este departamento.</p>
                            <p v-if="addressForm.province_id && !territoryLoading.districts && !districts.length" class="text-sm text-amber-700">No hay distritos activos para esta provincia.</p>
                            <label class="block text-sm font-medium">Teléfono<input v-model.trim="addressForm.phone" required maxlength="30" class="mt-1 min-h-11 w-full rounded-lg border px-3"></label>
                            <button type="button" :disabled="savingAddress || !addressForm.district_id" class="min-h-11 rounded-lg bg-blue-700 px-4 font-bold text-white disabled:opacity-50" @click="saveAddress">{{ savingAddress ? 'Guardando…' : 'Guardar y usar dirección' }}</button>
                        </div>
                        <div v-if="quoteLoading" class="rounded-lg bg-blue-50 p-4 text-blue-800"><i class="pi pi-spin pi-spinner mr-2"></i>Calculando costo de envío…</div>
                        <div v-else-if="quote && quote.has_coverage" class="rounded-lg bg-emerald-50 p-4 text-emerald-900"><b>Envío disponible · {{ quote.district }}</b><p>Zona: {{ quote.zone_name }} · S/ {{ money(quote.shipping_amount) }}</p><p v-if="quote.estimated_days_min != null" class="text-sm">Plazo estimado: {{ estimatedDays }}</p></div>
                        <div v-else-if="quote" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900">{{ quote.message }}</div>
                    </section>

                    <section v-else class="space-y-4">
                        <div><h2 class="text-lg font-bold">Selecciona una sede</h2><p class="text-sm text-slate-500">La sede es el destino de recojo; tu stock se reserva en el almacén web principal.</p></div>
                        <div class="grid gap-3">
                            <label v-for="branch in pickupBranches" :key="branch.id" class="cursor-pointer rounded-xl border p-4" :class="form.pickup_branch_id === branch.id ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-100' : 'border-slate-200 hover:border-blue-300'">
                                <div class="flex items-start gap-3"><input v-model="form.pickup_branch_id" type="radio" :value="branch.id" class="mt-1 h-5 w-5"><div><div class="font-bold">{{ branch.name }} <span class="font-mono text-xs text-slate-500">{{ branch.code }}</span></div><p class="text-sm text-slate-700">{{ branch.address }}<template v-if="branch.district"> — {{ branch.district }}</template></p><p v-if="branch.reference" class="mt-1 text-xs text-slate-500">Referencia: {{ branch.reference }}</p><p v-if="branch.business_hours" class="mt-1 text-xs text-slate-600"><i class="pi pi-clock mr-1"></i>{{ branch.business_hours }}</p><p v-if="branch.pickup_instructions" class="mt-1 text-xs text-slate-600">{{ branch.pickup_instructions }}</p></div></div>
                            </label>
                        </div>
                        <label class="block text-sm font-medium">Teléfono de contacto <span class="font-normal text-slate-500">(opcional)</span><input v-model.trim="form.phone" maxlength="30" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3"></label>
                        <div v-if="selectedBranch" class="rounded-lg bg-emerald-50 p-3 text-sm font-semibold text-emerald-800"><i class="pi pi-check-circle mr-2"></i>Recojo sin costo en {{ selectedBranch.name }}</div>
                    </section>

                    <section class="space-y-4 rounded-xl border border-slate-200 p-4 sm:p-5" aria-labelledby="receipt-heading">
                        <div><h2 id="receipt-heading" class="text-lg font-bold">Comprobante de venta</h2><p class="mt-1 text-sm text-slate-600">Solicitar un comprobante no significa que ya haya sido emitido.</p></div>
                        <p v-if="receiptOptionsLoading" class="text-sm text-slate-600" role="status">Cargando opciones de comprobante…</p>
                        <p v-else-if="receiptConfigError" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900" role="alert">{{ receiptConfigError }} <button type="button" class="font-bold underline" @click="loadReceiptOptions">Actualizar opciones</button></p>
                        <template v-else>
                        <div v-if="receiptOptions.enabled_types.length === 1" class="rounded-lg bg-blue-50 p-3 text-sm font-semibold text-blue-900">{{ receiptOptions.enabled_types[0].label }}</div>
                        <label v-else class="block text-sm font-semibold text-slate-700">Tipo de comprobante<select v-model="form.receipt_request.type" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option v-for="item in receiptOptions.enabled_types" :key="item.value" :value="item.value">{{ item.label }}</option></select><span v-if="receiptFieldError('type')" class="mt-1 block text-sm text-red-700">{{ receiptFieldError('type') }}</span></label>
                        <div v-if="form.receipt_request.type === 'boleta'" class="space-y-3 rounded-lg bg-slate-50 p-3">
                            <p class="text-sm text-slate-700">{{ boletaIdentityRequired ? 'Por el total del pedido, SUNAT requiere identificar al comprador.' : 'Puedes solicitar que la boleta incluya identificación.' }} La validación del documento es local; no consultamos SUNAT ni RENIEC.</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="text-sm font-semibold text-slate-700">Tipo de documento<select v-model="form.receipt_request.identity_type" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3" :required="boletaIdentityRequired || Boolean(form.receipt_request.identity_number)"><option value="">{{ boletaIdentityRequired ? 'Selecciona un documento' : 'Sin identificación' }}</option><option v-for="item in receiptOptions.identification_types || []" :key="item.value" :value="item.value">{{ item.label }}</option></select><span v-if="receiptFieldError('identity_type')" class="mt-1 block text-sm text-red-700">{{ receiptFieldError('identity_type') }}</span></label>
                                <label class="text-sm font-semibold text-slate-700">Número de documento<input v-model.trim="form.receipt_request.identity_number" type="text" inputmode="text" autocomplete="off" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3" :required="boletaIdentityRequired" :aria-invalid="Boolean(receiptFieldError('identity_number'))"><span v-if="receiptFieldError('identity_number')" class="mt-1 block text-sm text-red-700">{{ receiptFieldError('identity_number') }}</span></label>
                                <label class="text-sm font-semibold text-slate-700 sm:col-span-2">Nombre del titular<input v-model.trim="form.receipt_request.recipient_name" type="text" autocomplete="name" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3" :required="boletaIdentityRequired || Boolean(form.receipt_request.identity_number)" :aria-invalid="Boolean(receiptFieldError('recipient_name'))"><span v-if="receiptFieldError('recipient_name')" class="mt-1 block text-sm text-red-700">{{ receiptFieldError('recipient_name') }}</span></label>
                            </div>
                        </div>
                        <div v-else-if="form.receipt_request.type === 'factura'" class="grid gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-2">
                            <label class="text-sm font-semibold text-slate-700">RUC<input v-model.trim="form.receipt_request.identity_number" type="text" inputmode="numeric" maxlength="11" autocomplete="off" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3" required :aria-invalid="Boolean(receiptFieldError('identity_number'))"><span v-if="receiptFieldError('identity_number')" class="mt-1 block text-sm text-red-700">{{ receiptFieldError('identity_number') }}</span></label>
                            <label class="text-sm font-semibold text-slate-700">Razón social o nombre legal<input v-model.trim="form.receipt_request.recipient_name" type="text" autocomplete="organization" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3" required :aria-invalid="Boolean(receiptFieldError('recipient_name'))"><span v-if="receiptFieldError('recipient_name')" class="mt-1 block text-sm text-red-700">{{ receiptFieldError('recipient_name') }}</span></label>
                            <label class="text-sm font-semibold text-slate-700 sm:col-span-2">Dirección de facturación<input v-model.trim="form.receipt_request.billing_address" type="text" autocomplete="billing street-address" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3" required :aria-invalid="Boolean(receiptFieldError('billing_address'))"><span v-if="receiptFieldError('billing_address')" class="mt-1 block text-sm text-red-700">{{ receiptFieldError('billing_address') }}</span><button v-if="form.delivery_type === 'delivery' && selectedDeliveryAddress" type="button" class="mt-1 text-sm font-semibold text-blue-700 underline" @click="copyDeliveryToBilling">Copiar datos de entrega</button></label>
                        </div>
                        <label class="block text-sm font-semibold text-slate-700">Correo para recibir el comprobante<input v-model.trim="form.receipt_request.email" type="email" autocomplete="email" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3" required :aria-invalid="Boolean(receiptFieldError('email'))"><span v-if="receiptFieldError('email')" class="mt-1 block text-sm text-red-700">{{ receiptFieldError('email') }}</span></label>
                        </template>
                    </section>

                    <section>
                        <h2 class="mb-4 text-lg font-bold">Método de pago</h2>
                        <div v-if="loadingPaymentMethods" class="rounded-xl bg-slate-50 p-4 text-center text-slate-500 text-sm">
                            <i class="pi pi-spin pi-spinner mr-2"></i>Cargando métodos de pago disponibles…
                        </div>
                        <div v-else-if="!paymentMethods.length" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-900 text-sm">
                            <i class="pi pi-exclamation-circle mr-2 text-rose-600"></i>No hay métodos de pago habilitados para esta modalidad de entrega. Por favor contacta con soporte.
                        </div>
                        <div v-else class="space-y-3">
                            <div v-for="method in paymentMethods" :key="method.value" class="rounded-xl border transition" :class="form.payment_method === method.value ? 'border-blue-600 bg-blue-50/50' : 'border-slate-200'">
                                <label class="flex min-h-16 cursor-pointer items-center gap-3 p-4">
                                    <input v-model="form.payment_method" type="radio" :value="method.value" class="h-5 w-5 text-blue-600">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <b>{{ method.label }}</b>
                                            <span v-if="method.badge" class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">{{ method.badge }}</span>
                                        </div>
                                        <div class="text-sm text-slate-500">{{ method.description }}</div>
                                    </div>
                                </label>

                                <!-- Extra details for Transfer / Yape / Plin -->
                                <div v-if="form.payment_method === method.value && method.value === 'transferencia'" class="border-t border-blue-100 bg-blue-50/80 p-4 text-xs text-slate-700 space-y-3">
                                    <div v-if="method.bank_accounts?.length" class="space-y-2">
                                        <div class="font-bold text-slate-900">Cuentas Bancarias:</div>
                                        <div v-for="(acc, i) in method.bank_accounts" :key="i" class="rounded-lg bg-white p-2.5 border border-blue-200 font-mono text-[11px] space-y-0.5">
                                            <div><strong>{{ acc.bank }}:</strong> {{ acc.account }}</div>
                                            <div v-if="acc.cci"><span class="text-slate-500">CCI:</span> {{ acc.cci }}</div>
                                            <div v-if="acc.holder" class="font-sans text-slate-600 text-xs">Titular: {{ acc.holder }}</div>
                                        </div>
                                    </div>
                                    <div v-if="method.wallets?.length" class="flex flex-wrap gap-2 pt-1">
                                        <div v-for="(w, i) in method.wallets" :key="i" class="rounded-lg bg-white px-3 py-1.5 border border-blue-200 text-xs">
                                            <strong>{{ w.type }}:</strong> <span class="font-mono">{{ w.phone }}</span>
                                        </div>
                                    </div>
                                    <p v-if="method.instructions" class="font-sans text-slate-600 italic">{{ method.instructions }}</p>
                                </div>

                                <!-- Extra details for Cash on Delivery / Cash at Pickup -->
                                <div v-else-if="form.payment_method === method.value && method.instructions" class="border-t border-blue-100 bg-blue-50/80 p-3.5 text-xs text-slate-700">
                                    <i class="pi pi-info-circle text-blue-600 mr-1.5"></i>{{ method.instructions }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <div v-if="reservationExpiredMessage" class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-950" role="alert">
                        <p>{{ reservationExpiredMessage }}</p>
                        <button v-if="retryAttempted" type="button" class="min-h-11 rounded-lg border border-amber-800 px-4 font-bold" :disabled="processing || startingNewPurchase" @click="startNewPurchase">
                            {{ startingNewPurchase ? 'Revalidando…' : 'Iniciar una nueva compra' }}
                        </button>
                    </div>
                    <section v-if="priceChangePrompt" class="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950" role="alert" aria-live="polite">
                        <h2 class="font-bold">El precio cambió</h2>
                        <p class="text-sm">El total que revisaste era S/ {{ priceChangePrompt.previousTotal }}. El nuevo total es <strong>S/ {{ priceChangePrompt.total }}</strong>. Revisa los precios actualizados antes de continuar.</p>
                        <button type="button" class="min-h-11 rounded-lg bg-amber-800 px-4 font-bold text-white disabled:opacity-50" :disabled="processing" @click="acceptUpdatedPrice">{{ processing ? 'Actualizando pedido…' : 'Confirmar el nuevo total' }}</button>
                    </section>
                    <button :disabled="processing || !canSubmit || Boolean(priceChangePrompt)" class="min-h-12 w-full rounded-xl bg-green-600 px-4 font-bold text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50">{{ processing ? 'Procesando…' : 'Confirmar pedido' }}</button>
                </form>

                <aside class="bg-slate-50 p-5 md:p-8">
                    <h2 class="mb-5 text-lg font-bold">Tu pedido</h2>
                    <div class="max-h-64 space-y-3 overflow-y-auto">
                        <div v-for="item in cartStore.items" :key="item.product_id" class="flex items-start justify-between gap-3 text-sm">
                            <span class="flex min-w-0 items-start gap-2">
                                <img v-if="item.image" :src="item.image" :srcset="item.image_srcset" :sizes="item.image_sizes || '160px'" :width="item.image_dimensions?.width" :height="item.image_dimensions?.height" :alt="item.name" loading="lazy" class="h-10 w-10 shrink-0 rounded object-contain">
                                <span class="min-w-0">
                                    <span class="block">{{ item.quantity }}× {{ item.name }}</span>
                                    <span class="mt-1 flex flex-wrap items-center gap-x-2 text-xs">
                                        <span v-if="itemPriceBreakdown(item).hasDiscount" class="text-slate-500 line-through">S/ {{ money(itemPriceBreakdown(item).regularPrice) }}</span>
                                        <strong class="text-slate-800">S/ {{ money(itemPriceBreakdown(item).appliedPrice) }} c/u</strong>
                                    </span>
                                    <span v-if="itemPriceBreakdown(item).hasDiscount" class="mt-1 block text-xs font-semibold text-emerald-700">Ahorras S/ {{ money(itemPriceBreakdown(item).unitSavings) }} por unidad</span>
                                </span>
                            </span>
                            <b class="shrink-0">S/ {{ lineMoney(itemPriceBreakdown(item).appliedPrice, item.quantity) }}</b>
                        </div>
                    </div>
                    <div class="mt-6 space-y-2 border-t pt-5 text-sm">
                        <template v-if="checkoutPriceSummary.hasSavings">
                            <div class="flex justify-between"><span>Subtotal sin ofertas</span><span>S/ {{ money(checkoutPriceSummary.regularSubtotal) }}</span></div>
                            <div class="flex justify-between text-emerald-700"><span>Ahorro en ofertas</span><span>− S/ {{ money(checkoutPriceSummary.savings) }}</span></div>
                        </template>
                        <div class="flex justify-between"><span>Subtotal de productos</span><span>S/ {{ money(checkoutPriceSummary.productSubtotal) }}</span></div>
                        <div class="flex justify-between"><span>Costo de envío</span><b :class="form.delivery_type === 'pickup' || quote?.has_coverage ? 'text-emerald-700' : 'text-slate-600'">{{ shippingLabel }}</b></div>
                        <div class="mt-4 flex justify-between border-t pt-4 text-xl font-bold"><span>Total actual</span><span>S/ {{ money(quotedTotal) }}</span></div>
                        <div v-if="form.receipt_request.type" class="mt-4 rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-700"><b>Solicitud de comprobante</b><p class="mt-1">{{ selectedReceiptLabel }}</p><p v-if="form.receipt_request.recipient_name">{{ form.receipt_request.recipient_name }}</p><p v-if="form.receipt_request.identity_number">{{ form.receipt_request.identity_type }} · {{ form.receipt_request.identity_number }}</p><p>{{ form.receipt_request.email }}</p><p v-if="form.receipt_request.billing_address">{{ form.receipt_request.billing_address }}</p></div>
                        <p v-if="form.delivery_type === 'delivery'" class="text-xs text-slate-500">Laravel recalculará precios, stock y tarifa al confirmar.</p>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import api from '@/api';
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { useCartStore } from '@/stores/cart';
import { cartItemPriceBreakdown, summarizeCartPrices } from '@/utils/cartPricing.mjs';

const cartStore = useCartStore();
const authStore = useAuthStore();
const router = useRouter();
const toast = useToast();
const pickupBranches = ref([]);
const loadingPickupBranches = ref(false);
const pickupBranchesLoaded = ref(false);
const pickupBranchesError = ref('');
const processing = ref(false);
const startingNewPurchase = ref(false);
const retryAttempted = ref(false);
const reservationExpiredMessage = ref('');
const priceChangePrompt = ref(null);
const acceptedPriceConfirmationToken = ref(null);
const addresses = ref([]), legacyAddresses = ref([]), legacyConfirmationRequired = ref(false), legacyUnrecognizedCount = ref(0), addressesLoading = ref(true), showAddressForm = ref(false), savingAddress = ref(false);
const departments = ref([]), provinces = ref([]), districts = ref([]);
const territoryLoading = ref({ departments: false, provinces: false, districts: false });
const quote = ref(null), quoteLoading = ref(false);
const receiptOptions = ref({ enabled_types: [], identification_types: [], default_type: 'boleta', version: '' });
const receiptConfigError = ref('');
const receiptOptionsLoading = ref(true);
const receiptOptionsReady = ref(false);
const receiptErrors = ref({});
let quoteRequest = 0;
const form = ref({ address_id: null, phone: '', payment_method: 'card', delivery_type: 'delivery', pickup_branch_id: null, receipt_request: { type: 'boleta', identity_type: '', identity_number: '', recipient_name: authStore.user?.name || '', billing_address: '', email: authStore.user?.email || '' } });
const addressForm = ref({ label: '', recipient_name: authStore.user?.name || '', phone: authStore.user?.phone || '', address: '', reference: '', department_id: null, province_id: null, district_id: null });
const paymentMethods = ref([]);
const loadingPaymentMethods = ref(true);
const checkoutTokenValue = ref(checkoutToken());

const selectedBranch = computed(() => pickupBranches.value.find(branch => branch.id === form.value.pickup_branch_id));
const pickupAvailable = computed(() => pickupBranchesLoaded.value && !pickupBranchesError.value && pickupBranches.value.length > 0);
const canSubmitDelivery = computed(() => form.value.delivery_type === 'delivery' && Boolean(form.value.address_id && quote.value?.has_coverage && !quoteLoading.value));
const canSubmitPickup = computed(() => form.value.delivery_type === 'pickup' && pickupAvailable.value && Boolean(selectedBranch.value));
const hasPaymentMethod = computed(() => Boolean(form.value.payment_method && paymentMethods.value.some(m => m.value === form.value.payment_method)));
const canSubmit = computed(() => receiptOptionsReady.value && cartStore.items.length > 0 && hasPaymentMethod.value && (canSubmitDelivery.value || canSubmitPickup.value));
const boletaIdentityRequired = computed(() => form.value.receipt_request.type === 'boleta' && toMinor(quotedTotal.value) > toMinor(receiptOptions.value.boleta_identification_threshold || '700.00'));
const selectedReceiptLabel = computed(() => receiptOptions.value.enabled_types.find(item => item.value === form.value.receipt_request.type)?.label || 'Comprobante');
const selectedDeliveryAddress = computed(() => addresses.value.find(address => Number(address.id) === Number(form.value.address_id)));
const checkoutPriceSummary = computed(() => summarizeCartPrices(cartStore.items));
const itemPriceBreakdown = cartItemPriceBreakdown;
const quotedTotal = computed(() => fromMinor(toMinor(checkoutPriceSummary.value.productSubtotal) + (form.value.delivery_type === 'delivery' && quote.value?.has_coverage ? toMinor(quote.value.shipping_amount) : 0)));
const shippingLabel = computed(() => form.value.delivery_type === 'pickup' ? 'S/ 0.00' : quoteLoading.value ? 'Calculando…' : quote.value?.has_coverage ? `S/ ${money(quote.value.shipping_amount)}` : 'No calculado');
const estimatedDays = computed(() => quote.value?.estimated_days_min === quote.value?.estimated_days_max ? `${quote.value.estimated_days_min} día(s)` : `${quote.value?.estimated_days_min}–${quote.value?.estimated_days_max} días`);
const toMinor = value => { const [whole = '0', decimal = ''] = String(value ?? '0').split('.'); return (Number(whole) * 100) + Number(`${decimal}00`.slice(0, 2)); };
const fromMinor = value => `${Math.floor(value / 100)}.${String(value % 100).padStart(2, '0')}`;
const money = value => fromMinor(toMinor(value));
const lineMoney = (price, quantity) => fromMinor(toMinor(price) * quantity);

function checkoutToken() {
    let token = sessionStorage.getItem('checkout:idempotency-token');
    if (!token) { token = globalThis.crypto?.randomUUID?.() || `checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`; sessionStorage.setItem('checkout:idempotency-token', token); }
    return token;
}

function rotateCheckoutToken() {
    sessionStorage.removeItem('checkout:idempotency-token');
    checkoutTokenValue.value = checkoutToken();
    return checkoutTokenValue.value;
}

async function loadReceiptOptions() {
    receiptOptionsLoading.value = true;
    receiptConfigError.value = '';
    try {
        const options = (await api.get('/checkout/receipt-options')).data?.data;
        if (!options || !Array.isArray(options.enabled_types) || !options.enabled_types.length) throw new Error('No hay documentos habilitados.');
        receiptOptions.value = options;
        receiptOptionsReady.value = true;
        if (!options.enabled_types.some(item => item.value === form.value.receipt_request.type)) {
            form.value.receipt_request.type = options.default_type;
        }
        if (form.value.receipt_request.type === 'factura') form.value.receipt_request.identity_type = 'RUC';
    } catch {
        receiptOptionsReady.value = false;
        receiptConfigError.value = 'No se pudo cargar la configuración vigente de comprobantes. Actualiza las opciones antes de confirmar.';
    } finally {
        receiptOptionsLoading.value = false;
    }
}

function receiptFieldError(field) { return receiptErrors.value[`receipt_request.${field}`]?.[0] || ''; }

function copyDeliveryToBilling() {
    const address = selectedDeliveryAddress.value;
    if (!address) return;
    form.value.receipt_request.billing_address = [address.address, address.district, address.province, address.department].filter(Boolean).join(', ');
}

async function startNewPurchase() {
    if (startingNewPurchase.value || !cartStore.items.length) return;
    startingNewPurchase.value = true;
    try {
        const response = await api.post('/cart', { items: cartStore.items.map(item => ({ product_id: item.product_id, quantity: item.quantity })) });
        cartStore.applyPriceQuotes(response.data?.items);
        const invalidItem = response.data?.items?.find(item => !item.available);
        if (invalidItem || response.data?.valid === false) {
            toast.add({ severity: 'warn', summary: 'Stock no disponible', detail: 'La disponibilidad del carrito cambió. Revisa las cantidades antes de confirmar.', life: 6000 });
            return;
        }
        rotateCheckoutToken();
        retryAttempted.value = false;
        reservationExpiredMessage.value = '';
        toast.add({ severity: 'info', summary: 'Nueva compra', detail: 'El carrito fue revalidado. Confirma nuevamente cuando estés listo.', life: 5000 });
    } catch (error) {
        toast.add({ severity: 'error', summary: 'No se pudo revalidar', detail: error.response?.data?.message || error.message, life: 5000 });
    } finally {
        startingNewPurchase.value = false;
    }
}

async function loadPaymentMethods(deliveryType) {
    loadingPaymentMethods.value = true;
    try {
        const response = await api.get('/payment-methods', { params: { delivery_type: deliveryType } });
        paymentMethods.value = Array.isArray(response.data?.methods) ? response.data.methods : [];
        if (!paymentMethods.value.some(m => m.value === form.value.payment_method)) {
            form.value.payment_method = paymentMethods.value[0]?.value || null;
        }
    } catch {
        paymentMethods.value = [];
        form.value.payment_method = null;
    } finally {
        loadingPaymentMethods.value = false;
    }
}

function selectDelivery(type) {
    if (type === 'pickup' && !pickupAvailable.value) return;
    priceChangePrompt.value = null;
    acceptedPriceConfirmationToken.value = null;
    form.value.delivery_type = type;
    loadPaymentMethods(type);
    if (type === 'delivery') requestQuote(form.value.address_id);
    else { quote.value = null; quoteLoading.value = false; quoteRequest++; }
}

async function loadPickupBranches() {
    loadingPickupBranches.value = true;
    pickupBranchesLoaded.value = false;
    pickupBranchesError.value = '';
    try {
        const response = await api.get('/checkout/pickup-branches');
        if (!Array.isArray(response.data)) throw new TypeError('La respuesta de sedes no es un arreglo.');
        pickupBranches.value = response.data;
        pickupBranchesLoaded.value = true;
        if (!pickupBranches.value.some(branch => branch.id === form.value.pickup_branch_id)) form.value.pickup_branch_id = null;
    } catch {
        pickupBranches.value = [];
        pickupBranchesError.value = 'No pudimos consultar las sedes de recojo. Intenta nuevamente.';
    } finally {
        loadingPickupBranches.value = false;
    }
}

async function loadAddresses() { addressesLoading.value = true; try { const response = (await api.get('/addresses')).data; addresses.value = Array.isArray(response) ? response : response.data; legacyAddresses.value = Array.isArray(response) ? [] : response.legacy_addresses; legacyConfirmationRequired.value = !Array.isArray(response) && response.legacy_confirmation_required; legacyUnrecognizedCount.value = Array.isArray(response) ? 0 : response.legacy_unrecognized_count; if (!addresses.value.some(address => address.id === form.value.address_id)) form.value.address_id = addresses.value[0]?.id || null; showAddressForm.value = !addresses.value.length && !legacyConfirmationRequired.value; } catch { toast.add({ severity: 'error', summary: 'Direcciones', detail: 'No se pudieron cargar tus direcciones.', life: 4000 }); } finally { addressesLoading.value = false; } }
function reviewLegacyAddress(address) { for (const field of ['label', 'recipient_name', 'phone', 'address', 'reference']) if (address[field]) addressForm.value[field] = address[field]; addressForm.value.department_id = null; addressForm.value.province_id = null; addressForm.value.district_id = null; showAddressForm.value = true; }
async function loadDepartments() { territoryLoading.value.departments = true; try { departments.value = (await api.get('/location/departments')).data; } finally { territoryLoading.value.departments = false; } }
async function loadProvinces(departmentId) { provinces.value = []; districts.value = []; if (!departmentId) return; territoryLoading.value.provinces = true; try { provinces.value = (await api.get('/location/provinces', { params: { department_id: departmentId } })).data; } finally { territoryLoading.value.provinces = false; } }
async function loadDistricts(provinceId) { districts.value = []; if (!provinceId) return; territoryLoading.value.districts = true; try { districts.value = (await api.get('/location/districts', { params: { province_id: provinceId } })).data; } finally { territoryLoading.value.districts = false; } }
async function saveAddress() { savingAddress.value = true; try { const address = (await api.post('/addresses', addressForm.value)).data; addresses.value.unshift(address); form.value.address_id = address.id; form.value.phone = address.phone; showAddressForm.value = false; } catch (error) { toast.add({ severity: 'error', summary: 'Dirección inválida', detail: Object.values(error.response?.data?.errors || {})[0]?.[0] || error.message, life: 5000 }); } finally { savingAddress.value = false; } }
async function requestQuote(addressId) { const current = ++quoteRequest; quote.value = null; if (!addressId || form.value.delivery_type !== 'delivery') return; quoteLoading.value = true; try { const result = (await api.post('/checkout/shipping-quote', { address_id: addressId })).data; if (current === quoteRequest) quote.value = result; } catch (error) { if (current === quoteRequest) quote.value = { has_coverage: false, message: error.response?.data?.message || 'No se pudo calcular el envío.' }; } finally { if (current === quoteRequest) quoteLoading.value = false; } }

/**
 * Creates the order and triggers payment.
 * @param {boolean} isRetry - true only if this is an automatic single retry after reservation_expired.
 */
async function submitOrder(isRetry = false) {
    if (!authStore.isAuthenticated) return router.push('/login?redirect=/checkout');
    if (!canSubmit.value || processing.value || priceChangePrompt.value) return;
    processing.value = true;
    reservationExpiredMessage.value = '';
    try {
        const payload = {
            checkout_token: checkoutTokenValue.value,
            receipt_settings_version: receiptOptions.value.version,
            receipt_request: { ...form.value.receipt_request, identity_type: form.value.receipt_request.type === 'factura' ? 'RUC' : (form.value.receipt_request.identity_type || null) },
            expected_total: money(quotedTotal.value),
            ...(acceptedPriceConfirmationToken.value ? { price_confirmation_token: acceptedPriceConfirmationToken.value } : {}),
            items: cartStore.items.map(item => ({ product_id: item.product_id, quantity: item.quantity })),
            address_id: form.value.delivery_type === 'delivery' ? form.value.address_id : null,
            shipping_info: form.value.delivery_type === 'delivery' ? {} : { phone: form.value.phone },
            payment_method: form.value.payment_method,
            delivery_type: form.value.delivery_type,
            pickup_branch_id: form.value.delivery_type === 'pickup' ? form.value.pickup_branch_id : null,
        };

        let orderResponse;
        try {
            orderResponse = await api.post('/orders', payload);
        } catch (orderError) {
            if (orderError.response?.status === 409 && orderError.response?.data?.code === 'price_changed') {
                const body = orderError.response.data;
                const previousTotal = money(quotedTotal.value);
                priceChangePrompt.value = {
                    previousTotal,
                    total: money(body.total),
                    confirmationToken: body.confirmation_token,
                };
                acceptedPriceConfirmationToken.value = null;
                cartStore.applyPriceQuotes((body.items || []).map(line => ({
                    product_id: line.product_id,
                    regular_price: line.regular_price,
                    applied_price: line.price,
                })));
                if (quote.value && form.value.delivery_type === 'delivery') {
                    quote.value = { ...quote.value, shipping_amount: body.shipping_amount };
                }
                return;
            }
            if (orderError.response?.status === 409 && orderError.response?.data?.code === 'receipt_settings_changed') {
                const nextSettings = orderError.response.data.receipt_options;
                if (nextSettings) {
                    receiptOptions.value = nextSettings;
                    receiptOptionsReady.value = Array.isArray(nextSettings.enabled_types) && nextSettings.enabled_types.length > 0;
                    if (!nextSettings.enabled_types?.some(item => item.value === form.value.receipt_request.type)) form.value.receipt_request.type = nextSettings.default_type;
                    if (form.value.receipt_request.type === 'factura') form.value.receipt_request.identity_type = 'RUC';
                } else await loadReceiptOptions();
                receiptConfigError.value = orderError.response.data.message || 'La configuración de comprobantes cambió.';
                acceptedPriceConfirmationToken.value = null;
                return;
            }
            // Handle 409 reservation_expired from /orders endpoint
            if (orderError.response?.status === 409 && orderError.response?.data?.code === 'reservation_expired') {
                if (isRetry) {
                    retryAttempted.value = true;
                    reservationExpiredMessage.value = orderError.response.data.message || 'El pedido o su reserva ya no se encuentran vigentes.';
                    toast.add({
                        severity: 'warn',
                        summary: 'Reserva no vigente',
                        detail: reservationExpiredMessage.value,
                        life: 7000,
                    });
                    return;
                }
                // Rotate token, inform user, and attempt once with a new token
                rotateCheckoutToken();
                toast.add({
                    severity: 'info',
                    summary: 'Reserva vencida',
                    detail: 'La reserva previa venció. Revalidando stock y datos del carrito…',
                    life: 4000,
                });
                // Revalidate shipping quote before retry
                if (form.value.delivery_type === 'delivery' && form.value.address_id) {
                    await requestQuote(form.value.address_id);
                }
                processing.value = false;
                await submitOrder(true);
                return;
            }
            throw orderError;
        }

        const order = orderResponse.data;

        if (form.value.payment_method === 'card') {
            let paymentResponse;
            try {
                paymentResponse = await api.post('/payment/create', { order_id: order.id });
            } catch (payError) {
                // Handle 409 reservation_expired from /payment/create endpoint
                if (payError.response?.status === 409 && payError.response?.data?.code === 'reservation_expired') {
                    if (isRetry) {
                        retryAttempted.value = true;
                        reservationExpiredMessage.value = payError.response.data.message || 'El pedido o su reserva ya no se encuentran vigentes.';
                        toast.add({
                            severity: 'warn',
                            summary: 'Reserva no vigente',
                            detail: reservationExpiredMessage.value,
                            life: 7000,
                        });
                        return;
                    }
                    rotateCheckoutToken();
                    toast.add({
                        severity: 'info',
                        summary: 'Reserva vencida',
                        detail: 'La reserva previa venció. Revalidando stock y datos del carrito…',
                        life: 4000,
                    });
                    if (form.value.delivery_type === 'delivery' && form.value.address_id) {
                        await requestQuote(form.value.address_id);
                    }
                    processing.value = false;
                    await submitOrder(true);
                    return;
                }
                throw payError;
            }
            sessionStorage.removeItem('checkout:idempotency-token');
            acceptedPriceConfirmationToken.value = null;
            cartStore.clear();
            window.location.href = paymentResponse.data.checkout_url;
            return;
        }

        sessionStorage.removeItem('checkout:idempotency-token');
        acceptedPriceConfirmationToken.value = null;
        cartStore.clear();
        router.push({ name: 'OrderSuccess', params: { id: order.id } });
    } catch (error) {
        receiptErrors.value = error.response?.data?.errors || {};
        toast.add({
            severity: 'error',
            summary: 'No se pudo confirmar',
            detail: error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || error.message,
            life: 5000,
        });
    } finally {
        processing.value = false;
    }
}

function acceptUpdatedPrice() {
    if (processing.value || !priceChangePrompt.value?.confirmationToken) return;
    acceptedPriceConfirmationToken.value = priceChangePrompt.value.confirmationToken;
    priceChangePrompt.value = null;
    submitOrder(false);
}

watch(() => form.value.address_id, addressId => requestQuote(addressId));
watch(() => addressForm.value.department_id, async id => { addressForm.value.province_id = null; addressForm.value.district_id = null; await loadProvinces(id); });
watch(() => addressForm.value.province_id, async id => { addressForm.value.district_id = null; await loadDistricts(id); });
onMounted(() => {
    loadReceiptOptions();
    loadPaymentMethods(form.value.delivery_type);
    if (authStore.isAuthenticated) Promise.all([loadPickupBranches(), loadAddresses(), loadDepartments()]);
    else { loadingPickupBranches.value = false; addressesLoading.value = false; }
});
</script>
