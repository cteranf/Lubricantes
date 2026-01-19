<template>
    <AdminLayout>
        <div class="container mx-auto px-6 py-8">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-gray-700 text-3xl font-medium">Dashboard</h3>
                
                <!-- Date Filters -->
                <div class="flex gap-3 items-center">
                    <select v-model="filters.year" @change="fetchData" class="border rounded px-3 py-2 text-sm">
                        <option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option>
                    </select>
                    
                    <select v-model="filters.month" @change="fetchData" class="border rounded px-3 py-2 text-sm">
                        <option :value="null">Todos los meses</option>
                        <option value="1">Enero</option>
                        <option value="2">Febrero</option>
                        <option value="3">Marzo</option>
                        <option value="4">Abril</option>
                        <option value="5">Mayo</option>
                        <option value="6">Junio</option>
                        <option value="7">Julio</option>
                        <option value="8">Agosto</option>
                        <option value="9">Septiembre</option>
                        <option value="10">Octubre</option>
                        <option value="11">Noviembre</option>
                        <option value="12">Diciembre</option>
                    </select>
                    
                    <button @click="resetFilters" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 rounded text-sm">
                        <i class="pi pi-refresh mr-1"></i> Resetear
                    </button>
                </div>
            </div>

            <!-- Metrics Cards -->
            <div class="mt-4">
                <div class="flex flex-wrap -mx-6">
                    <div class="w-full px-6 sm:w-1/2 xl:w-1/4">
                        <div class="flex items-center px-5 py-6 shadow-sm rounded-md bg-white">
                            <div class="p-3 rounded-full bg-indigo-600 bg-opacity-75">
                                <i class="pi pi-dollar text-white text-2xl"></i>
                            </div>
                            <div class="mx-5">
                                <h4 class="text-2xl font-semibold text-gray-700">S/ {{ stats.total_sales?.toLocaleString() }}</h4>
                                <div class="text-gray-500">Ventas Totales</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="w-full px-6 sm:w-1/2 xl:w-1/4 mt-4 sm:mt-0">
                        <div class="flex items-center px-5 py-6 shadow-sm rounded-md bg-white">
                            <div class="p-3 rounded-full bg-orange-600 bg-opacity-75">
                                <i class="pi pi-shopping-cart text-white text-2xl"></i>
                            </div>
                            <div class="mx-5">
                                <h4 class="text-2xl font-semibold text-gray-700">{{ stats.orders_count }}</h4>
                                <div class="text-gray-500">Pedidos</div>
                            </div>
                        </div>
                    </div>

                    <div class="w-full px-6 sm:w-1/2 xl:w-1/4 mt-4 xl:mt-0">
                        <div class="flex items-center px-5 py-6 shadow-sm rounded-md bg-white">
                             <div class="p-3 rounded-full bg-pink-600 bg-opacity-75">
                                <i class="pi pi-box text-white text-2xl"></i>
                            </div>
                            <div class="mx-5">
                                <h4 class="text-2xl font-semibold text-gray-700">{{ stats.products_count }}</h4>
                                <div class="text-gray-500">Productos</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="w-full px-6 sm:w-1/2 xl:w-1/4 mt-4 xl:mt-0">
                        <div class="flex items-center px-5 py-6 shadow-sm rounded-md bg-white">
                            <div class="p-3 rounded-full bg-green-600 bg-opacity-75">
                                <i class="pi pi-users text-white text-2xl"></i>
                            </div>
                            <div class="mx-5">
                                <h4 class="text-2xl font-semibold text-gray-700">{{ stats.client_count }}</h4>
                                <div class="text-gray-500">Clientes</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts & Tables -->
            <div class="mt-8">
                <div class="flex flex-col md:flex-row gap-8">
                    <!-- Sales Chart -->
                    <div class="w-full md:w-2/3 bg-white rounded-lg shadow p-6">
                        <h4 class="text-lg font-bold mb-4">Ventas Mensuales ({{ filters.year }}{{ filters.month ? ' - ' + getMonthName(filters.month) : '' }})</h4>
                        <Chart v-if="salesChartData" type="bar" :data="salesChartData" />
                    </div>

                    <!-- Top Products Table -->
                    <div class="w-full md:w-1/3 bg-white rounded-lg shadow p-6">
                         <h4 class="text-lg font-bold mb-4">Productos Más Vendidos</h4>
                         <ul class="space-y-4">
                             <li v-for="product in stats.top_products" :key="product.id" class="flex justify-between items-center border-b pb-2">
                                 <div class="flex items-center">
                                     <div class="w-10 h-10 bg-gray-200 rounded mr-3">
                                         <img v-if="product.image_path" :src="product.image_path" class="w-full h-full object-cover rounded">
                                     </div>
                                     <span class="text-sm font-medium">{{ product.name.substring(0, 20) }}...</span>
                                 </div>
                                 <span class="font-bold text-blue-600">{{ product.total_sold }} un.</span>
                             </li>
                         </ul>
                    </div>
                </div>
            </div>

            <!-- Low Stock Alert -->
            <div class="mt-8" v-if="stats.low_stock_products?.length > 0">
                <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded shadow">
                    <h4 class="text-red-700 font-bold mb-2">Alerta de Stock Bajo</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div v-for="prod in stats.low_stock_products" :key="prod.id" class="flex justify-between items-center bg-white p-2 rounded border">
                            <span class="text-sm">{{ prod.name }}</span>
                            <span class="bg-red-100 text-red-800 text-xs font-bold px-2 py-1 rounded">{{ prod.stock }} unid.</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>

<script setup>
import AdminLayout from '@/layouts/AdminLayout.vue';
import Chart from '@/components/Chart.vue';
import { ref, onMounted, computed } from 'vue';
import api from '@/api';

const stats = ref({});
const filters = ref({
    year: new Date().getFullYear(),
    month: null,
    start_date: null,
    end_date: null
});

const availableYears = computed(() => {
    const currentYear = new Date().getFullYear();
    return Array.from({ length: 5 }, (_, i) => currentYear - i);
});

const salesChartData = computed(() => {
    if (!stats.value.sales_chart) return null;
    
    const labels = stats.value.sales_chart.map(d => {
        const date = new Date();
        date.setMonth(d.month - 1);
        return date.toLocaleString('es-PE', { month: 'short' });
    });
    
    const data = stats.value.sales_chart.map(d => d.total);

    return {
        labels,
        datasets: [{
            label: 'Ventas (S/)',
            data: data,
            backgroundColor: '#4F46E5',
            borderRadius: 5,
        }]
    };
});

const fetchData = async () => {
    try {
        const params = {};
        if (filters.value.year) params.year = filters.value.year;
        if (filters.value.month) params.month = filters.value.month;
        if (filters.value.start_date) params.start_date = filters.value.start_date;
        if (filters.value.end_date) params.end_date = filters.value.end_date;
        
        const response = await api.get('/admin/dashboard', { params });
        stats.value = response.data;
    } catch (e) {
        console.error(e);
    }
};

const resetFilters = () => {
    filters.value = {
        year: new Date().getFullYear(),
        month: null,
        start_date: null,
        end_date: null
    };
    fetchData();
};

const getMonthName = (month) => {
    const months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 
                    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    return months[month - 1];
};

onMounted(() => {
    fetchData();
});
</script>
