<template>
    <AppLayout>
        <!-- Hero Slider (Loading State) -->
        <div v-if="loading" class="w-full h-[500px] bg-gray-200 animate-pulse relative">
            <div class="absolute inset-0 flex flex-col items-center justify-center p-4">
                <Skeleton width="40%" height="3rem" class="mb-4 bg-gray-300"></Skeleton>
                <Skeleton width="60%" height="1.5rem" class="mb-8 bg-gray-300"></Skeleton>
                <Skeleton width="150px" height="3rem" borderRadius="2rem" class="bg-gray-300"></Skeleton>
            </div>
        </div>

        <!-- Hero Slider (Content) -->
        <div v-else class="card">
            <Carousel :value="slides" :numVisible="1" :numScroll="1" :circular="true" :autoplayInterval="5000" :showIndicators="true">
                <template #item="slotProps">
                    <div class="relative w-full h-[500px] overflow-hidden">
                        <img :src="slotProps.data.image" :alt="slotProps.data.title" class="w-full h-full object-cover filter brightness-50" />
                        <div class="absolute inset-0 flex flex-col items-center justify-center text-center text-white p-4">
                            <h2 class="text-4xl md:text-6xl font-bold mb-4 animate-fade-in-down">{{ slotProps.data.title }}</h2>
                            <p class="text-xl md:text-2xl mb-8 max-w-2xl mx-auto animate-fade-in-up">{{ slotProps.data.description }}</p>
                            <router-link to="/catalog" class="bg-yellow-500 text-gray-900 font-bold py-3 px-8 rounded-full hover:bg-yellow-400 transition shadow-lg animate-bounce-in">
                                Ver Catálogo
                            </router-link>
                        </div>
                    </div>
                </template>
            </Carousel>
        </div>

        <!-- Categories Section -->
        <section class="py-16 bg-gray-50">
            <div class="container mx-auto px-4">
                <h2 class="text-3xl font-bold text-center mb-12">Nuestras Categorías</h2>
                
                <div v-if="loading" class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <div v-for="i in 4" :key="i" class="p-6 rounded-lg shadow bg-white flex flex-col items-center">
                         <Skeleton shape="circle" size="6rem" class="mb-4"></Skeleton>
                         <Skeleton width="60%" height="1.5rem"></Skeleton>
                    </div>
                </div>

                <div v-else class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <router-link :to="'/catalog?category=' + category.slug" v-for="category in categories" :key="category.id" class="bg-white p-6 rounded-lg shadow hover:shadow-lg transition cursor-pointer text-center group flex flex-col items-center">
                        <div class="h-24 w-24 rounded-full mx-auto mb-4 flex items-center justify-center overflow-hidden bg-gray-100">
                             <img v-if="category.image" :src="category.image" :alt="category.name" class="w-full h-full object-cover">
                             <i v-else class="pi pi-box text-3xl text-gray-400 group-hover:text-blue-600 transition"></i>
                        </div>
                        <h3 class="font-semibold text-lg">{{ category.name }}</h3>
                    </router-link>
                </div>
            </div>
        </section>

        <!-- Featured Products -->
        <section class="py-16">
            <div class="container mx-auto px-4">
                 <div class="flex justify-between items-center mb-12">
                    <h2 class="text-3xl font-bold">Destacados</h2>
                    <router-link to="/catalog" class="text-blue-600 hover:underline">Ver todo</router-link>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                     <!-- Placeholder Products used until API is connected -->
                     <ProductCard v-for="i in 4" :key="i" :product="placeholderProduct" />
                </div>
            </div>
        </section>

        <!-- News Section -->
        <section class="py-16 bg-gray-50">
            <div class="container mx-auto px-4">
                 <h2 class="text-3xl font-bold text-center mb-12">Noticias y Blog</h2>
                 
                 <div v-if="loading" class="grid grid-cols-1 md:grid-cols-3 gap-8">
                      <div v-for="i in 3" :key="i" class="bg-white rounded-lg shadow overflow-hidden">
                           <Skeleton width="100%" height="200px"></Skeleton>
                           <div class="p-6">
                               <Skeleton width="80%" height="1.5rem" class="mb-4"></Skeleton>
                               <Skeleton width="100%" height="1rem" class="mb-2"></Skeleton>
                               <Skeleton width="100%" height="1rem" class="mb-2"></Skeleton>
                               <Skeleton width="60%" height="1rem"></Skeleton>
                           </div>
                      </div>
                 </div>

                 <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-8">
                     <div v-for="news in newsList" :key="news.id" class="bg-white rounded-lg shadow hover:shadow-lg transition overflow-hidden">
                         <img :src="news.image_path || 'https://via.placeholder.com/400x200'" :alt="news.title" class="w-full h-48 object-cover">
                         <div class="p-6">
                             <h3 class="font-bold text-xl mb-2">{{ news.title }}</h3>
                             <p class="text-gray-600 mb-4 line-clamp-3">{{ news.summary }}</p>
                             <!-- Simple read more link/modal could go here -->
                             <span class="text-blue-600 font-semibold text-sm">Leer más <i class="pi pi-arrow-right ml-1"></i></span>
                         </div>
                     </div>
                 </div>
            </div>
        </section>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import ProductCard from '@/components/ProductCard.vue';
import Carousel from 'primevue/carousel';
import Skeleton from 'primevue/skeleton';
import { ref, onMounted } from 'vue';
import api from '@/api';

const slides = ref([]);
const categories = ref([]);
const newsList = ref([]);
const loading = ref(true);

onMounted(async () => {
    loading.value = true;
    try {
        // Reduced latency simulation for dev if needed, but here we just fetch
        const [slidersResponse, categoriesResponse, newsResponse] = await Promise.all([
            api.get('/sliders'),
            api.get('/categories'),
            api.get('/news')
        ]);

        // Sliders
        if (slidersResponse.data.length > 0) {
            slides.value = slidersResponse.data.map(s => ({
                title: s.title,
                description: s.description,
                image: s.image_path
            }));
        } else {
             slides.value = [
                {
                    title: "El mejor aceite para tu motor",
                    description: "Encuentra lubricantes de alta gama, filtros y aditivos para mantener tu vehículo en perfectas condiciones.",
                    image: "https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?q=80&w=1600&auto=format&fit=crop"
                }
             ];
        }

        // Categories
        // Categories
        categories.value = categoriesResponse.data;

        // News
        newsList.value = newsResponse.data;

    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
});

const placeholderProduct = {
    id: 1,
    name: "Aceite Sintético 5W-30",
    brand: { name: "DemoBrand" },
    price: 120.00,
    image_path: null // Use placeholder in component
};
</script>

<style scoped>
.animate-fade-in-down {
    animation: fadeInDown 1s ease-out;
}
.animate-fade-in-up {
    animation: fadeInUp 1s ease-out 0.5s both;
}
.animate-bounce-in {
    animation: bounceIn 1s ease-out 1s both;
}

@keyframes fadeInDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes bounceIn {
    0% { opacity: 0; transform: scale(0.3); }
    50% { opacity: 1; transform: scale(1.05); }
    70% { transform: scale(0.9); }
    100% { transform: scale(1); }
}
</style>
