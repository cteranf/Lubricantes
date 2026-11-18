<template>
    <AppLayout><div class="container mx-auto px-4 py-16 text-center"><div v-if="loading" class="mx-auto max-w-lg rounded-lg bg-white p-8 shadow"><i class="pi pi-spin pi-spinner text-4xl text-blue-600"></i></div><div v-else-if="error" class="mx-auto max-w-lg rounded-lg bg-white p-8 text-red-700 shadow">{{ error }}</div><div v-else class="mx-auto max-w-lg rounded-lg border border-green-100 bg-white p-8 shadow"><i class="pi pi-check-circle text-6xl text-green-600"></i><h1 class="mt-4 text-3xl font-bold">¡Pedido registrado!</h1><p class="mt-2 text-slate-600">Pedido #{{ order.id }} · Pago {{ paymentStatusLabel }}</p>
        <div v-if="order.delivery_type === 'pickup'" class="my-6 rounded-lg bg-blue-50 p-4 text-left"><b>Recojo en sede · {{ order.pickup_branch_name_snapshot }}</b><p>{{ order.pickup_address_snapshot }}<template v-if="order.pickup_district_snapshot"> — {{ order.pickup_district_snapshot }}</template></p><p v-if="order.pickup_business_hours_snapshot" class="mt-2 text-sm">Horario: {{ order.pickup_business_hours_snapshot }}</p><p v-if="order.pickup_instructions_snapshot" class="mt-1 text-sm">{{ order.pickup_instructions_snapshot }}</p><p class="mt-2 text-sm text-slate-600">Te avisaremos cuando esté listo y comience el plazo de recojo.</p></div>
        <div v-else class="my-6 rounded-lg bg-slate-50 p-4 text-left"><b>Envío a domicilio</b><p>{{ order.shipping_info?.address }} — {{ order.shipping_district_snapshot || order.shipping_info?.district }}</p><p class="mt-1 text-sm">Zona: {{ order.shipping_zone_name_snapshot || 'Configuración histórica' }}</p><p v-if="order.shipping_estimated_days_min_snapshot != null" class="text-sm">Plazo: {{ estimate(order) }}</p></div>
        <dl class="mb-6 space-y-2 border-t pt-4 text-sm text-left">
            <div class="flex justify-between gap-4"><dt>Subtotal sin ofertas</dt><dd class="tabular-nums">{{ formatPen(orderPriceSummary.regularSubtotal) }}</dd></div>
            <div v-if="orderPriceSummary.hasSavings" class="flex justify-between gap-4 text-emerald-700"><dt>Ahorro en ofertas</dt><dd class="tabular-nums">− {{ formatPen(orderPriceSummary.savings) }}</dd></div>
            <div v-if="Number(order.discount_total) > 0" class="flex justify-between gap-4"><dt>Descuentos comerciales</dt><dd class="tabular-nums">− {{ formatPen(order.discount_total) }}</dd></div>
            <div v-if="Number(order.shipping_amount) > 0" class="flex justify-between gap-4"><dt>Costo de envío</dt><dd class="tabular-nums">{{ formatPen(order.shipping_amount) }}</dd></div>
            <div class="flex justify-between gap-4 border-t pt-3 text-lg font-black"><dt>{{ isPaymentConfirmed ? 'Total pagado' : 'Total a pagar' }}</dt><dd class="tabular-nums">{{ formatPen(order.total) }}</dd></div>
        </dl>
        <div class="space-y-3"><router-link :to="`/orders/${order.id}/tracking`" class="block rounded-lg bg-blue-600 py-3 font-bold text-white">Seguir mi pedido</router-link><router-link to="/catalog" class="block rounded-lg bg-slate-100 py-3 font-bold">Continuar comprando</router-link></div></div></div></AppLayout>
</template>
<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import AppLayout from '@/layouts/AppLayout.vue';
import api from '@/api';
import { summarizeOrderPriceSnapshots } from '@/utils/cartPricing.mjs';
import { formatPen } from '@/utils/productPresentation';
const route = useRoute(), order = ref(null), loading = ref(true), error = ref('');
const orderPriceSummary = computed(() => summarizeOrderPriceSnapshots(order.value?.items || []));
const isPaymentConfirmed = computed(() => order.value?.payment_status === 'approved');
const paymentStatusLabel = computed(() => order.value?.payment_status === 'pending' ? 'pendiente' : order.value?.payment_status === 'approved' ? 'confirmado' : order.value?.payment_status || 'pendiente');
const estimate = value => value.shipping_estimated_days_min_snapshot === value.shipping_estimated_days_max_snapshot ? `${value.shipping_estimated_days_min_snapshot} día(s)` : `${value.shipping_estimated_days_min_snapshot}–${value.shipping_estimated_days_max_snapshot} días`;
onMounted(async () => { try { order.value = (await api.get(`/orders/${route.params.id}`)).data; } catch (exception) { error.value = exception.response?.status === 404 ? 'El pedido no existe o no pertenece a tu cuenta.' : 'No se pudo cargar el pedido.'; } finally { loading.value = false; } });
</script>
