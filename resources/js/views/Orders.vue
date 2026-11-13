<template>
  <AppLayout>
    <main class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8" aria-labelledby="orders-title">
      <header class="mb-7">
        <p class="text-sm font-bold uppercase tracking-[0.14em] text-blue-700">Mi cuenta</p>
        <h1 id="orders-title" class="mt-1 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Mis pedidos</h1>
        <p class="mt-2 text-base text-slate-600">Consulta tus compras y revisa su seguimiento.</p>
      </header>

      <div class="grid min-w-0 gap-6 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-8">
        <nav class="grid h-fit grid-cols-1 gap-1 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm sm:grid-cols-2 lg:flex lg:flex-col" aria-label="Navegación de Mi cuenta">
          <router-link
            to="/profile"
            class="account-link"
            active-class="account-link-active"
          >
            <i class="pi pi-user" aria-hidden="true"></i>
            <span>Información personal</span>
          </router-link>
          <router-link
            to="/orders"
            class="account-link account-link-active"
            aria-current="page"
          >
            <i class="pi pi-receipt" aria-hidden="true"></i>
            <span>Mis pedidos</span>
          </router-link>
          <button type="button" class="account-link account-link-logout" @click="logout">
            <i class="pi pi-sign-out" aria-hidden="true"></i>
            <span>Cerrar sesión</span>
          </button>
        </nav>

        <section class="min-w-0" aria-labelledby="history-heading" :aria-busy="loading">
          <div class="mb-5 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 xl:flex-row xl:items-center xl:justify-between">
            <div>
              <h2 id="history-heading" class="text-xl font-black text-slate-950">Historial de compras</h2>
              <p class="mt-1 text-sm text-slate-600">{{ orders.length }} {{ orders.length === 1 ? 'pedido en tu historial' : 'pedidos en tu historial' }}</p>
            </div>
            <label class="relative block w-full xl:max-w-sm">
              <span class="sr-only">Buscar por número de pedido</span>
              <i class="pi pi-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
              <input
                v-model.trim="search"
                type="search"
                inputmode="search"
                autocomplete="off"
                class="min-h-12 w-full rounded-xl border border-slate-300 bg-white py-2 pl-10 pr-3 text-sm text-slate-900 placeholder:text-slate-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
                placeholder="Buscar pedido por número"
                aria-label="Buscar pedido por número"
              >
            </label>
          </div>

          <div class="mb-5 flex flex-wrap gap-2" role="group" aria-label="Filtrar pedidos por estado">
            <button
              v-for="filter in filters"
              :key="filter.id"
              type="button"
              class="min-h-10 rounded-full border px-4 py-2 text-sm font-bold transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 motion-reduce:transition-none"
              :class="activeFilter === filter.id ? 'border-blue-800 bg-blue-800 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-blue-400 hover:bg-blue-50'"
              :aria-pressed="activeFilter === filter.id"
              @click="activeFilter = filter.id"
            >
              {{ filter.label }} <span class="ml-1 text-xs" :class="activeFilter === filter.id ? 'text-blue-100' : 'text-slate-500'">{{ filterCount(filter.id) }}</span>
            </button>
          </div>

          <div v-if="loading" class="space-y-4" role="status" aria-label="Cargando pedidos">
            <div v-for="skeleton in 3" :key="skeleton" class="animate-pulse rounded-2xl border border-slate-200 bg-white p-5 shadow-sm motion-reduce:animate-none">
              <div class="h-5 w-40 rounded bg-slate-200"></div>
              <div class="mt-5 h-16 rounded bg-slate-100"></div>
              <div class="mt-4 h-10 rounded bg-slate-100"></div>
            </div>
            <p class="sr-only">Cargando tus pedidos…</p>
          </div>

          <div v-else-if="error" class="rounded-2xl border border-rose-200 bg-white p-7 text-center shadow-sm sm:p-10" role="alert">
            <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-rose-50 text-rose-700"><i class="pi pi-exclamation-triangle text-2xl" aria-hidden="true"></i></span>
            <h3 class="mt-4 text-lg font-black text-slate-950">No pudimos cargar tus pedidos</h3>
            <p class="mt-2 text-sm text-slate-600">{{ error }}</p>
            <button type="button" class="mt-5 min-h-11 rounded-xl bg-blue-800 px-5 font-bold text-white hover:bg-blue-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 disabled:opacity-60" :disabled="loading" @click="fetchOrders">Reintentar</button>
          </div>

          <div v-else-if="orders.length === 0" class="rounded-2xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm sm:px-10">
            <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-blue-50 text-blue-800"><i class="pi pi-shopping-bag text-2xl" aria-hidden="true"></i></span>
            <h3 class="mt-5 text-xl font-black text-slate-950">Aún no tienes pedidos</h3>
            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-600">Cuando realices una compra, podrás consultar aquí su estado y seguimiento.</p>
            <router-link to="/catalog" class="mt-6 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-blue-800 px-5 font-bold text-white hover:bg-blue-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">
              <i class="pi pi-arrow-right" aria-hidden="true"></i>Explorar productos
            </router-link>
          </div>

          <div v-else-if="visibleOrders.length === 0" class="rounded-2xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm sm:px-10">
            <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-100 text-slate-600"><i class="pi pi-search text-xl" aria-hidden="true"></i></span>
            <h3 class="mt-4 text-lg font-black text-slate-950">No encontramos pedidos</h3>
            <p class="mt-2 text-sm text-slate-600">Prueba con otro número o limpia los filtros seleccionados.</p>
            <button type="button" class="mt-5 min-h-11 rounded-xl border border-slate-300 px-5 font-bold text-slate-800 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" @click="clearFilters">Limpiar filtros</button>
          </div>

          <div v-else class="space-y-4">
            <article v-for="order in visibleOrders" :key="order.id" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition-shadow hover:shadow-md motion-reduce:transition-none">
              <header class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div class="min-w-0">
                  <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Número de pedido</p>
                  <h3 class="mt-1 text-lg font-black text-slate-950">#{{ orderNumber(order.id) }}</h3>
                  <p class="mt-1 text-sm text-slate-600">{{ formatDate(order.created_at) }}</p>
                </div>
                <span class="inline-flex w-fit max-w-full items-center gap-2 rounded-full px-3 py-2 text-sm font-bold" :class="stateFor(order).classes" :aria-label="`Estado: ${stateFor(order).label}`">
                  <i :class="stateFor(order).icon" aria-hidden="true"></i><span>{{ stateFor(order).label }}</span>
                </span>
              </header>

              <div class="p-4 sm:p-5">
                <div class="mb-4 flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 sm:p-4">
                  <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg" :class="order.delivery_type === 'pickup' ? 'bg-cyan-50 text-cyan-800' : 'bg-blue-50 text-blue-800'">
                    <i :class="order.delivery_type === 'pickup' ? 'pi pi-shop' : 'pi pi-truck'" aria-hidden="true"></i>
                  </span>
                  <div class="min-w-0">
                    <p class="font-bold text-slate-900">{{ order.delivery_type === 'pickup' ? 'Recojo en sede' : 'Envío a domicilio' }}</p>
                    <p v-if="order.delivery_type === 'pickup'" class="mt-1 break-words text-sm text-slate-600">{{ order.pickup_branch_name_snapshot || 'Sede de recojo' }}<span v-if="order.pickup_district_snapshot"> · {{ order.pickup_district_snapshot }}</span></p>
                    <p v-else class="mt-1 break-words text-sm text-slate-600">{{ order.shipping_district_snapshot || order.shipping_zone_name_snapshot || 'Destino registrado al comprar' }}</p>
                    <p v-if="order.delivery_type === 'pickup' && order.pickup_deadline_at && order.pickup_deadline_status !== 'picked_up'" class="mt-1 text-xs text-slate-500">Fecha límite de recojo: {{ formatDate(order.pickup_deadline_at) }}</p>
                  </div>
                </div>

                <ul class="divide-y divide-slate-100" :aria-label="`Productos del pedido ${orderNumber(order.id)}`">
                  <li v-for="(item, index) in visibleItems(order)" :key="`${order.id}-${index}`" class="grid min-w-0 grid-cols-[3.5rem_minmax(0,1fr)_auto] items-center gap-3 py-3 first:pt-0 last:pb-0">
                    <span class="grid h-14 w-14 place-items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                      <img v-if="itemImage(item) && !imageFailed(order.id, index)" :src="itemImage(item)" :alt="`Imagen de ${item.product?.name || 'producto'}`" class="h-full w-full object-contain" width="56" height="56" loading="lazy" @error="markImageFailed(order.id, index)">
                      <span v-else class="grid h-full w-full place-items-center" role="img" :aria-label="`Imagen no disponible para ${item.product?.name || 'producto'}`"><i class="pi pi-image text-lg text-slate-400" aria-hidden="true"></i></span>
                    </span>
                    <div class="min-w-0">
                      <p class="break-words text-sm font-bold leading-5 text-slate-900">{{ item.product?.name || 'Producto' }}</p>
                      <p class="mt-1 text-xs text-slate-600">Cantidad: {{ item.quantity }}</p>
                    </div>
                    <p class="whitespace-nowrap text-right text-sm font-bold tabular-nums text-slate-800">{{ formatPen(item.subtotal ?? historicalSubtotal(item)) }}</p>
                  </li>
                </ul>

                <div v-if="order.items?.length > visibleItemLimit" class="mt-3">
                  <button type="button" class="min-h-10 rounded-lg px-2 text-sm font-bold text-blue-800 underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" :aria-expanded="isExpanded(order)" @click="toggleExpanded(order)">
                    {{ isExpanded(order) ? 'Mostrar menos productos' : `Ver ${order.items.length - visibleItemLimit} productos más` }}
                  </button>
                </div>

                <footer class="mt-5 flex flex-col gap-4 border-t border-slate-200 pt-4 sm:flex-row sm:items-end sm:justify-between">
                  <router-link :to="`/orders/${order.id}/tracking`" class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-blue-800 px-4 text-center text-sm font-bold text-white hover:bg-blue-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 sm:w-auto">
                    <i class="pi pi-map-marker" aria-hidden="true"></i>Ver detalle y seguimiento
                  </router-link>
                  <div class="flex items-end justify-between gap-4 sm:justify-end">
                    <div class="text-right text-xs leading-5 text-slate-600">
                      <p>Envío: {{ formatPen(order.shipping_amount) }}</p>
                      <p>{{ order.items?.length || 0 }} {{ order.items?.length === 1 ? 'producto' : 'productos' }}</p>
                    </div>
                    <div class="text-right">
                      <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Total del pedido</p>
                      <p class="mt-1 text-xl font-black tabular-nums text-slate-950">{{ formatPen(order.total) }}</p>
                    </div>
                  </div>
                </footer>
              </div>
            </article>
            <p class="px-1 text-right text-xs text-slate-500" aria-live="polite">Mostrando {{ visibleOrders.length }} de {{ orders.length }} {{ orders.length === 1 ? 'pedido' : 'pedidos' }}</p>
          </div>
        </section>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AppLayout from '@/layouts/AppLayout.vue';
import { useAuthStore } from '@/stores/auth';
import api from '@/api';
import { formatDateTime } from '@/utils/dateTime';
import { formatPen, normalizeImageUrl } from '@/utils/productPresentation';
import { customerOrderNumber, customerOrderState } from '@/utils/customerOrderPresentation';

const router = useRouter();
const authStore = useAuthStore();
const orders = ref([]);
const loading = ref(true);
const error = ref('');
const search = ref('');
const activeFilter = ref('all');
const expandedOrderIds = ref(new Set());
const failedImageKeys = ref(new Set());
const visibleItemLimit = 3;
const stateFor = customerOrderState;
let requestController = null;
let requestSequence = 0;

const filters = [
  { id: 'all', label: 'Todos' },
  { id: 'pending', label: 'Pendientes' },
  { id: 'preparing', label: 'En preparación' },
  { id: 'ready', label: 'Listos para entrega o recojo' },
  { id: 'completed', label: 'Completados' },
  { id: 'canceled', label: 'Cancelados' },
];

const visibleOrders = computed(() => {
  const term = search.value.trim().replace(/^#/, '').toLocaleLowerCase('es-PE');
  return orders.value.filter((order) => {
    const matchesState = activeFilter.value === 'all' || stateFor(order).group === activeFilter.value;
    if (!matchesState) return false;
    if (!term) return true;
    const id = String(order.id ?? '').toLocaleLowerCase('es-PE');
    const number = orderNumber(order.id).toLocaleLowerCase('es-PE');
    return id.includes(term) || number.includes(term);
  });
});

function filterCount(filterId) {
  return filterId === 'all' ? orders.value.length : orders.value.filter((order) => stateFor(order).group === filterId).length;
}

const orderNumber = customerOrderNumber;

function formatDate(value) {
  return formatDateTime(value, 'Fecha no disponible');
}

function historicalSubtotal(item) {
  const price = Number(item?.price);
  const quantity = Number(item?.quantity);
  return Number.isFinite(price) && Number.isFinite(quantity) ? (price * quantity).toFixed(2) : 0;
}

function itemImage(item) {
  return normalizeImageUrl(item?.product?.image_url);
}

function imageKey(orderId, itemIndex) {
  return `${orderId}:${itemIndex}`;
}

function imageFailed(orderId, itemIndex) {
  return failedImageKeys.value.has(imageKey(orderId, itemIndex));
}

function markImageFailed(orderId, itemIndex) {
  failedImageKeys.value = new Set([...failedImageKeys.value, imageKey(orderId, itemIndex)]);
}

function visibleItems(order) {
  const items = Array.isArray(order.items) ? order.items : [];
  return isExpanded(order) ? items : items.slice(0, visibleItemLimit);
}

function isExpanded(order) {
  return expandedOrderIds.value.has(order.id);
}

function toggleExpanded(order) {
  const next = new Set(expandedOrderIds.value);
  if (next.has(order.id)) next.delete(order.id);
  else next.add(order.id);
  expandedOrderIds.value = next;
}

function clearFilters() {
  search.value = '';
  activeFilter.value = 'all';
}

async function fetchOrders() {
  requestController?.abort();
  requestController = new AbortController();
  const controller = requestController;
  const sequence = ++requestSequence;
  loading.value = true;
  error.value = '';

  try {
    const response = await api.get('/orders', { signal: controller.signal });
    if (sequence !== requestSequence) return;
    orders.value = Array.isArray(response.data) ? response.data : [];
    expandedOrderIds.value = new Set();
    failedImageKeys.value = new Set();
  } catch (requestError) {
    if (requestError?.code === 'ERR_CANCELED' || requestError?.name === 'AbortError') return;
    if (sequence === requestSequence) {
      orders.value = [];
      error.value = 'Inténtalo nuevamente en unos momentos.';
    }
  } finally {
    if (sequence === requestSequence) {
      loading.value = false;
      if (requestController === controller) requestController = null;
    }
  }
}

async function logout() {
  await authStore.logout();
  await router.replace('/login');
}

onMounted(() => {
  if (authStore.isAuthenticated) fetchOrders();
  else router.replace('/login');
});

onBeforeUnmount(() => {
  requestSequence += 1;
  requestController?.abort();
  requestController = null;
});
</script>

<style scoped>
.account-link {
  display: flex;
  min-height: 2.875rem;
  align-items: center;
  gap: .75rem;
  border-radius: .75rem;
  padding: .7rem .8rem;
  color: #334155;
  font-size: .875rem;
  font-weight: 700;
  outline-offset: 2px;
  transition: color .15s ease, background-color .15s ease;
}

.account-link:hover { background: #f1f5f9; color: #0f172a; }
.account-link:focus-visible { outline: 2px solid #1d4ed8; }
.account-link-active { background: #eff6ff; color: #1e40af; }
.account-link-logout { width: 100%; text-align: left; }

@media (min-width: 1024px) {
  .account-link-logout { margin-top: .35rem; border-top: 1px solid #e2e8f0; border-radius: 0 0 .75rem .75rem; padding-top: 1rem; color: #475569; }
}

@media (prefers-reduced-motion: reduce) {
  .account-link { transition: none; }
}
</style>
