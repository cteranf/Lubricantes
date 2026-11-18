<template>
    <AdminLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6">
            <header class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wide text-blue-700">Catálogo</p>
                    <h1 class="mt-1 text-2xl font-bold text-slate-900">Ofertas web por producto</h1>
                    <p class="mt-1 text-sm text-slate-600">Define un precio promocional fijo y su periodo de vigencia.</p>
                </div>
                <button type="button" class="min-h-11 rounded-lg bg-blue-700 px-4 font-semibold text-white hover:bg-blue-800" @click="resetForm">Nueva oferta</button>
            </header>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="promotion-form-title">
                <h2 id="promotion-form-title" class="text-lg font-bold text-slate-900">{{ form.id ? 'Editar oferta' : 'Crear oferta' }}</h2>
                <p class="mt-1 text-sm text-slate-600">Las fechas se interpretan en <strong>{{ timezone }}</strong>.</p>
                <form class="mt-5 grid gap-4 md:grid-cols-2" @submit.prevent="save">
                    <div class="md:col-span-2">
                        <label for="promotion-product-search" class="block text-sm font-semibold text-slate-800">Producto</label>
                        <input id="promotion-product-search" v-model.trim="productSearch" type="search" autocomplete="off" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3" placeholder="Buscar por nombre, modelo o SKU" :disabled="Boolean(form.id)" @input="searchProducts">
                        <div v-if="selectedProduct" class="mt-2 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm" aria-live="polite">
                            <strong>{{ selectedProduct.name }}</strong><span class="ml-2 text-slate-600">{{ selectedProduct.model || selectedProduct.sku }}</span>
                            <span class="ml-2">Precio normal: S/ {{ selectedProduct.price }}</span>
                        </div>
                        <ul v-if="productOptions.length" class="mt-2 max-h-52 divide-y overflow-y-auto rounded-lg border border-slate-200" aria-label="Resultados de productos">
                            <li v-for="product in productOptions" :key="product.id">
                                <button type="button" class="min-h-12 w-full px-3 py-2 text-left hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" @click="selectProduct(product)">
                                    <span class="font-semibold">{{ product.name }}</span><span class="ml-2 text-sm text-slate-600">{{ product.model ? `${product.model} · ` : '' }}SKU {{ product.sku }} · S/ {{ product.price }}</span>
                                </button>
                            </li>
                        </ul>
                        <p v-if="productSearch.length && !searchingProducts && !productOptions.length && !selectedProduct" class="mt-2 text-sm text-slate-600">No se encontraron productos.</p>
                    </div>
                    <label class="text-sm font-semibold text-slate-800">Precio promocional
                        <span class="mt-1 flex min-h-11 items-center rounded-lg border border-slate-300 px-3"><span class="mr-2 text-slate-500">S/</span><input v-model.trim="form.promotion_price" type="number" min="0.01" step="0.01" required class="min-w-0 flex-1 border-0 p-0 focus:ring-0" :max="selectedProduct?.price || undefined"></span>
                    </label>
                    <label class="flex min-h-11 items-center gap-3 self-end rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-800"><input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-700"> Oferta habilitada</label>
                    <label class="text-sm font-semibold text-slate-800">Inicio ({{ timezone }})<input v-model="form.starts_at" type="datetime-local" required class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"></label>
                    <label class="text-sm font-semibold text-slate-800">Fin ({{ timezone }})<input v-model="form.ends_at" type="datetime-local" required class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"></label>
                    <p v-if="errorMessage" class="md:col-span-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ errorMessage }}</p>
                    <div class="flex flex-wrap gap-2 md:col-span-2">
                        <button type="submit" class="min-h-11 rounded-lg bg-blue-700 px-5 font-semibold text-white disabled:opacity-50" :disabled="saving || !selectedProduct">{{ saving ? 'Guardando…' : form.id ? 'Guardar cambios' : 'Crear oferta' }}</button>
                        <button v-if="form.id" type="button" class="min-h-11 rounded-lg border border-slate-300 px-4 font-semibold text-slate-700" :disabled="saving" @click="resetForm">Cancelar edición</button>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-label="Ofertas registradas">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4"><h2 class="font-bold text-slate-900">Ofertas registradas</h2><span class="text-sm text-slate-600">{{ meta.total || 0 }} en total</span></div>
                <div v-if="loading" class="p-8 text-center text-slate-500" role="status">Cargando ofertas…</div>
                <p v-else-if="!promotions.length" class="p-8 text-center text-slate-600">Aún no hay ofertas registradas.</p>
                <div v-else class="divide-y divide-slate-100">
                    <article v-for="promotion in promotions" :key="promotion.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-slate-900">{{ promotion.product.name }}</h3>
                            <p class="text-sm text-slate-600">{{ promotion.product.model ? `${promotion.product.model} · ` : '' }}SKU {{ promotion.product.sku }}</p>
                            <p class="mt-1 text-sm"><span class="text-slate-500 line-through">S/ {{ promotion.product.normal_price }}</span><strong class="ml-2 text-emerald-700">S/ {{ promotion.promotion_price }}</strong></p>
                            <p class="text-xs text-slate-500">{{ formatDate(promotion.starts_at) }} – {{ formatDate(promotion.ends_at) }} · {{ promotion.business_timezone }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-xs font-bold" :class="promotion.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'">{{ promotion.is_active ? 'Habilitada' : 'Desactivada' }}</span>
                            <button type="button" class="min-h-10 rounded-lg border border-slate-300 px-3 text-sm font-semibold" @click="edit(promotion)">Editar</button>
                            <button type="button" class="min-h-10 rounded-lg border px-3 text-sm font-semibold" :class="promotion.is_active ? 'border-amber-300 text-amber-800' : 'border-emerald-300 text-emerald-800'" :disabled="saving" @click="toggleStatus(promotion)">{{ promotion.is_active ? 'Desactivar' : 'Activar' }}</button>
                        </div>
                    </article>
                </div>
                <footer v-if="meta.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-5 py-3">
                    <button type="button" class="min-h-10 rounded border px-3 disabled:opacity-40" :disabled="page <= 1" @click="load(page - 1)">Anterior</button><span class="text-sm text-slate-600">Página {{ page }} de {{ meta.last_page }}</span><button type="button" class="min-h-10 rounded border px-3 disabled:opacity-40" :disabled="page >= meta.last_page" @click="load(page + 1)">Siguiente</button>
                </footer>
            </section>
        </div>
    </AdminLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api from '@/api';
import AdminLayout from '@/layouts/AdminLayout.vue';

const promotions = ref([]);
const productOptions = ref([]);
const selectedProduct = ref(null);
const productSearch = ref('');
const searchingProducts = ref(false);
const loading = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const timezone = ref('UTC');
const page = ref(1);
const meta = ref({ total: 0, last_page: 1 });
const form = ref(emptyForm());
let searchTimer;
let searchSequence = 0;

function emptyForm() {
    const start = new Date();
    start.setSeconds(0, 0);
    const end = new Date(start.getTime() + 24 * 60 * 60 * 1000);
    const localValue = date => {
        const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone.value, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date);
        const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}T${values.hour}:${values.minute}`;
    };
    return { id: null, promotion_price: '', starts_at: localValue(start), ends_at: localValue(end), is_active: true };
}

async function load(nextPage = 1) {
    loading.value = true;
    try {
        const { data } = await api.get('/admin/product-promotions', { params: { page: nextPage } });
        promotions.value = Array.isArray(data.data) ? data.data : [];
        meta.value = data.meta || { total: 0, last_page: 1 };
        timezone.value = meta.value.business_timezone || timezone.value;
        if (!form.value.id && !selectedProduct.value && !productSearch.value) form.value = emptyForm();
        page.value = nextPage;
    } catch {
        errorMessage.value = 'No se pudieron cargar las ofertas.';
    } finally {
        loading.value = false;
    }
}

function searchProducts() {
    clearTimeout(searchTimer);
    const sequence = ++searchSequence;
    productOptions.value = [];
    if (productSearch.value.trim().length < 1) return;
    searchTimer = setTimeout(async () => {
        searchingProducts.value = true;
        try {
            const { data } = await api.get('/admin/product-promotions/products', { params: { search: productSearch.value.trim() } });
            if (sequence === searchSequence) productOptions.value = Array.isArray(data) ? data : [];
        } catch {
            if (sequence === searchSequence) errorMessage.value = 'No se pudieron buscar productos.';
        } finally {
            if (sequence === searchSequence) searchingProducts.value = false;
        }
    }, 250);
}

function selectProduct(product) {
    selectedProduct.value = product;
    productSearch.value = `${product.name} · ${product.sku}`;
    productOptions.value = [];
}

function resetForm() {
    form.value = emptyForm();
    selectedProduct.value = null;
    productSearch.value = '';
    errorMessage.value = '';
}

function edit(promotion) {
    form.value = { id: promotion.id, promotion_price: promotion.promotion_price, starts_at: promotion.starts_at, ends_at: promotion.ends_at, is_active: promotion.is_active };
    selectedProduct.value = { id: promotion.product.id, name: promotion.product.name, sku: promotion.product.sku, model: promotion.product.model, price: promotion.product.normal_price };
    productSearch.value = `${selectedProduct.value.name} · ${selectedProduct.value.sku}`;
    errorMessage.value = '';
    document.getElementById('promotion-form-title')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function save() {
    if (saving.value || !selectedProduct.value) return;
    saving.value = true;
    errorMessage.value = '';
    try {
        const payload = { product_id: selectedProduct.value.id, promotion_price: form.value.promotion_price, starts_at: form.value.starts_at, ends_at: form.value.ends_at, is_active: form.value.is_active };
        if (form.value.id) await api.put(`/admin/product-promotions/${form.value.id}`, payload);
        else await api.post('/admin/product-promotions', payload);
        resetForm();
        await load(1);
    } catch (error) {
        errorMessage.value = Object.values(error.response?.data?.errors || {})[0]?.[0] || error.response?.data?.message || 'No se pudo guardar la oferta.';
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(promotion) {
    if (saving.value) return;
    saving.value = true;
    errorMessage.value = '';
    try {
        await api.patch(`/admin/product-promotions/${promotion.id}/status`, { is_active: !promotion.is_active });
        await load(page.value);
    } catch (error) {
        errorMessage.value = Object.values(error.response?.data?.errors || {})[0]?.[0] || error.response?.data?.message || 'No se pudo cambiar el estado de la oferta.';
    } finally {
        saving.value = false;
    }
}

function formatDate(value) {
    return String(value || '').replace('T', ' ');
}

onMounted(() => load());
</script>
