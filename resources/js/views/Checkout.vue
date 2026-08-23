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

                    <section>
                        <h2 class="mb-4 text-lg font-bold">Método de pago</h2>
                        <div class="space-y-3">
                            <label v-for="method in paymentMethods" :key="method.value" class="flex min-h-16 cursor-pointer items-center gap-3 rounded-xl border p-4" :class="form.payment_method === method.value ? 'border-blue-600 bg-blue-50' : 'border-slate-200'"><input v-model="form.payment_method" type="radio" :value="method.value" class="h-5 w-5"><div><b>{{ method.label }}</b><div class="text-sm text-slate-500">{{ method.description }}</div></div></label>
                        </div>
                    </section>

                    <button :disabled="processing || !canSubmit" class="min-h-12 w-full rounded-xl bg-green-600 px-4 font-bold text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50">{{ processing ? 'Procesando…' : 'Confirmar pedido' }}</button>
                </form>

                <aside class="bg-slate-50 p-5 md:p-8">
                    <h2 class="mb-5 text-lg font-bold">Tu pedido</h2>
                    <div class="max-h-64 space-y-3 overflow-y-auto"><div v-for="item in cartStore.items" :key="item.product_id" class="flex justify-between gap-3 text-sm"><span>{{ item.quantity }}× {{ item.name }}</span><b>S/ {{ lineMoney(item.price, item.quantity) }}</b></div></div>
                    <div class="mt-6 space-y-2 border-t pt-5 text-sm"><div class="flex justify-between"><span>Subtotal de productos</span><span>S/ {{ money(cartStore.total) }}</span></div><div class="flex justify-between"><span>Descuentos comerciales</span><span>− S/ 0.00</span></div><div class="flex justify-between"><span>Costo de envío</span><b :class="form.delivery_type === 'pickup' || quote?.has_coverage ? 'text-emerald-700' : 'text-slate-600'">{{ shippingLabel }}</b></div><div class="mt-4 flex justify-between border-t pt-4 text-xl font-bold"><span>Total actual</span><span>S/ {{ money(quotedTotal) }}</span></div><p v-if="form.delivery_type === 'delivery'" class="text-xs text-slate-500">Laravel recalculará precios, stock y tarifa al confirmar.</p></div>
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

const cartStore = useCartStore();
const authStore = useAuthStore();
const router = useRouter();
const toast = useToast();
const pickupBranches = ref([]);
const loadingPickupBranches = ref(false);
const pickupBranchesLoaded = ref(false);
const pickupBranchesError = ref('');
const processing = ref(false);
const addresses = ref([]), legacyAddresses = ref([]), legacyConfirmationRequired = ref(false), legacyUnrecognizedCount = ref(0), addressesLoading = ref(true), showAddressForm = ref(false), savingAddress = ref(false);
const departments = ref([]), provinces = ref([]), districts = ref([]);
const territoryLoading = ref({ departments: false, provinces: false, districts: false });
const quote = ref(null), quoteLoading = ref(false);
let quoteRequest = 0;
const form = ref({ address_id: null, phone: '', payment_method: 'card', delivery_type: 'delivery', pickup_branch_id: null });
const addressForm = ref({ label: '', recipient_name: authStore.user?.name || '', phone: authStore.user?.phone || '', address: '', reference: '', department_id: null, province_id: null, district_id: null });
const paymentMethods = [
    { value: 'card', label: 'Tarjeta de crédito o débito', description: 'Pago mediante la pasarela disponible' },
    { value: 'transferencia', label: 'Transferencia bancaria', description: 'Pendiente de verificación administrativa' },
    { value: 'contra_entrega', label: 'Pago al recibir o recoger', description: 'Cobro confirmado al completar la entrega' },
];
const selectedBranch = computed(() => pickupBranches.value.find(branch => branch.id === form.value.pickup_branch_id));
const pickupAvailable = computed(() => pickupBranchesLoaded.value && !pickupBranchesError.value && pickupBranches.value.length > 0);
const canSubmitDelivery = computed(() => form.value.delivery_type === 'delivery' && Boolean(form.value.address_id && quote.value?.has_coverage && !quoteLoading.value));
const canSubmitPickup = computed(() => form.value.delivery_type === 'pickup' && pickupAvailable.value && Boolean(selectedBranch.value));
const canSubmit = computed(() => cartStore.items.length > 0 && (canSubmitDelivery.value || canSubmitPickup.value));
const quotedTotal = computed(() => fromMinor(toMinor(cartStore.total) + (form.value.delivery_type === 'delivery' && quote.value?.has_coverage ? toMinor(quote.value.shipping_amount) : 0)));
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
function selectDelivery(type) { if (type === 'pickup' && !pickupAvailable.value) return; form.value.delivery_type = type; if (type === 'delivery') requestQuote(form.value.address_id); else { quote.value = null; quoteLoading.value = false; quoteRequest++; } }
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
async function submitOrder() {
    if (!authStore.isAuthenticated) return router.push('/login?redirect=/checkout');
    if (!canSubmit.value || processing.value) return;
    processing.value = true;
    try {
        const payload = {
            checkout_token: checkoutToken(),
            items: cartStore.items.map(item => ({ product_id: item.product_id, quantity: item.quantity })),
            address_id: form.value.delivery_type === 'delivery' ? form.value.address_id : null,
            shipping_info: form.value.delivery_type === 'delivery' ? {} : { phone: form.value.phone },
            payment_method: form.value.payment_method,
            delivery_type: form.value.delivery_type,
            pickup_branch_id: form.value.delivery_type === 'pickup' ? form.value.pickup_branch_id : null,
        };
        const order = (await api.post('/orders', payload)).data;
        if (form.value.payment_method === 'card') {
            const payment = (await api.post('/payment/create', { order_id: order.id })).data;
            sessionStorage.removeItem('checkout:idempotency-token'); cartStore.clear(); window.location.href = payment.checkout_url; return;
        }
        sessionStorage.removeItem('checkout:idempotency-token'); cartStore.clear(); router.push({ name: 'OrderSuccess', params: { id: order.id } });
    } catch (error) { toast.add({ severity: 'error', summary: 'No se pudo confirmar', detail: error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || error.message, life: 5000 }); }
    finally { processing.value = false; }
}
watch(() => form.value.address_id, addressId => requestQuote(addressId));
watch(() => addressForm.value.department_id, async id => { addressForm.value.province_id = null; addressForm.value.district_id = null; await loadProvinces(id); });
watch(() => addressForm.value.province_id, async id => { addressForm.value.district_id = null; await loadDistricts(id); });
onMounted(() => { if (authStore.isAuthenticated) Promise.all([loadPickupBranches(), loadAddresses(), loadDepartments()]); else { loadingPickupBranches.value = false; addressesLoading.value = false; } });
</script>
