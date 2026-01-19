<template>
    <AppLayout>
        <div class="container mx-auto px-4 py-8">
            <div class="flex flex-col md:flex-row gap-8">
                <!-- Sidebar Filters -->
                <aside class="w-full md:w-1/4 bg-white p-6 rounded-lg shadow h-fit">
                    <h3 class="font-bold text-lg mb-4">Filtros</h3>
                    
                    <div class="mb-6">
                        <h4 class="font-medium mb-2">Categorías</h4>
                        <ul class="space-y-1 text-sm text-gray-600">
                            <li><button @click="filterCategory(null)" class="hover:text-blue-600">Todas</button></li>
                            <li v-for="cat in categories" :key="cat.id">
                                <button @click="filterCategory(cat.slug)" :class="{'text-blue-600 font-bold': currentCategory === cat.slug}" class="hover:text-blue-600">{{ cat.name }}</button>
                            </li>
                        </ul>
                    </div>
                </aside>

                <!-- Product Grid -->
                <div class="w-full md:w-3/4">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold">Catálogo</h2>
                        <select v-model="sortBy" @change="fetchProducts" class="border rounded px-3 py-1">
                            <option value="newest">Más recientes</option>
                            <option value="price_asc">Menor precio</option>
                            <option value="price_desc">Mayor precio</option>
                        </select>
                    </div>

                    <div v-if="loading" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div v-for="i in 6" :key="i" class="bg-white p-4 rounded-lg shadow h-[400px]">
                            <Skeleton width="100%" height="200px" class="mb-4"></Skeleton>
                            <Skeleton width="60%" height="1.5rem" class="mb-2"></Skeleton>
                            <Skeleton width="40%" height="1rem" class="mb-4"></Skeleton>
                            <Skeleton width="30%" height="2rem" borderRadius="16px"></Skeleton>
                        </div>
                    </div>
                    <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <ProductCard v-for="product in products" :key="product.id" :product="product" />
                    </div>
                    
                    <!-- Pagination (Simple) -->
                    <div class="mt-8 flex justify-center space-x-2" v-if="meta.last_page > 1">
                         <button @click="changePage(meta.current_page - 1)" :disabled="meta.current_page <= 1" class="px-3 py-1 border rounded disabled:opacity-50">Anterior</button>
                         <span class="px-3 py-1">Página {{ meta.current_page }} de {{ meta.last_page }}</span>
                         <button @click="changePage(meta.current_page + 1)" :disabled="meta.current_page >= meta.last_page" class="px-3 py-1 border rounded disabled:opacity-50">Siguiente</button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import ProductCard from '@/components/ProductCard.vue';
import Skeleton from 'primevue/skeleton';
import { ref, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '@/api';

const products = ref([]);
const categories = ref([]);
const meta = ref({});
const loading = ref(true);
const sortBy = ref('newest');
const currentCategory = ref(null);

const route = useRoute();
const router = useRouter();

const fetchProducts = async (page = 1) => {
    loading.value = true;
    try {
        const params = {
            page,
            sort: sortBy.value,
            category: currentCategory.value,
            search: route.query.search
        };
        const response = await api.get('/products', { params });
        products.value = response.data.data;
        meta.value = response.data; // meta might be directly in response.data depending on pagination resource
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

const fetchCategories = async () => {
    const response = await api.get('/categories');
    categories.value = response.data;
};

const filterCategory = (slug) => {
    currentCategory.value = slug;
    fetchProducts();
};

const changePage = (page) => {
    fetchProducts(page);
    window.scrollTo(0,0);
};

onMounted(() => {
    fetchCategories();
    if(route.query.category) currentCategory.value = route.query.category;
    fetchProducts();
});

watch(() => route.query.search, () => {
    fetchProducts();
});
</script>
