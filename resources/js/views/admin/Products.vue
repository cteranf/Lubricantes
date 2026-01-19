<template>
    <AdminLayout>
        <div class="container mx-auto px-6 py-8">
            <div class="flex justify-between items-center mb-6">
                 <h3 class="text-gray-700 text-3xl font-medium">Gestión de Productos</h3>
                 <button @click="openModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                     <i class="pi pi-plus mr-2"></i> Nuevo Producto
                 </button>
            </div>

            <!-- List -->
            <div class="bg-white shadow-md rounded-lg overflow-hidden">
                <table class="min-w-full leading-normal">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Producto</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Precio</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Stock</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="product in products" :key="product.id">
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 w-10 h-10">
                                        <img class="w-full h-full rounded-full object-cover" :src="product.image_path || 'https://via.placeholder.com/150'" alt="" />
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-gray-900 whitespace-no-wrap font-bold">{{ product.name }}</p>
                                        <p class="text-gray-600 whitespace-no-wrap text-xs">{{ product.category?.name }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <p class="text-gray-900 whitespace-no-wrap">S/ {{ product.price }}</p>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">
                                <span :class="product.stock < 5 ? 'bg-red-200 text-red-900' : 'bg-green-200 text-green-900'" class="px-2 py-1 leading-tight rounded-full text-xs font-semibold">
                                    {{ product.stock }}
                                </span>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">
                                <button @click="openModal(product)" class="text-blue-600 hover:text-blue-900 mr-4"><i class="pi pi-pencil"></i></button>
                                <button @click="deleteProduct(product.id)" class="text-red-600 hover:text-red-900"><i class="pi pi-trash"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                 <!-- Pagination could be added here -->
            </div>

            <!-- Modal -->
             <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center bg-black bg-opacity-50">
                <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6">
                    <h3 class="text-xl font-bold mb-4">{{ isEditing ? 'Editar Producto' : 'Nuevo Producto' }}</h3>
                    <form @submit.prevent="submitForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                             <label class="block text-gray-700 text-sm font-bold mb-2">Nombre</label>
                             <input v-model="form.name" type="text" class="w-full border rounded px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" required>
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2">Precio</label>
                            <input v-model="form.price" type="number" step="0.01" class="w-full border rounded px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" required>
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2">Stock</label>
                            <input v-model="form.stock" type="number" class="w-full border rounded px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" required>
                        </div>
                        
                        <!-- Simplified Category/Brand selection (assuming IDs known orfetched separately, skipping for brevity but placeholder logic included) -->
                        <div>
                             <label class="block text-gray-700 text-sm font-bold mb-2">Categoría ID</label>
                             <input v-model="form.category_id" type="number" class="w-full border rounded px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                         <div>
                             <label class="block text-gray-700 text-sm font-bold mb-2">Marca ID</label>
                             <input v-model="form.brand_id" type="number" class="w-full border rounded px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-gray-700 text-sm font-bold mb-2">Imagen</label>
                            <input type="file" @change="handleFileUpload" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                             <p v-if="isEditing && !form.image" class="text-xs text-gray-500 mt-1">Deja vacío para mantener la actual.</p>
                        </div>

                        <div class="md:col-span-2">
                             <label class="block text-gray-700 text-sm font-bold mb-2">Descripción</label>
                             <textarea v-model="form.description" class="w-full border rounded px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                        </div>

                         <div class="md:col-span-2 flex justify-end space-x-3 mt-4">
                            <button type="button" @click="showModal = false" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">Cancelar</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700" :disabled="processing">
                                {{ processing ? 'Guardando...' : 'Guardar' }}
                            </button>
                        </div>
                    </form>
                </div>
             </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ref, onMounted } from 'vue';
import api from '@/api';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';

const toast = useToast();
const confirm = useConfirm();

const products = ref([]);
const showModal = ref(false);
const isEditing = ref(false);
const processing = ref(false);
const form = ref({});

const fetchProducts = async () => {
    try {
        const response = await api.get('/admin/products');
        // Handle pagination response vs array response
        products.value = response.data.data ? response.data.data : response.data;
    } catch(e) {
        console.error(e);
    }
};

const openModal = (product = null) => {
    isEditing.value = !!product;
    if (product) {
        form.value = { ...product, image: null };
    } else {
        form.value = { name: '', price: 0, stock: 0, category_id: 1, brand_id: 1, image: null }; // Default IDs for demo
    }
    showModal.value = true;
};

const handleFileUpload = (event) => {
    form.value.image = event.target.files[0];
};

const submitForm = async () => {
    processing.value = true;
    try {
        const formData = new FormData();
        formData.append('name', form.value.name);
        formData.append('price', form.value.price);
        formData.append('stock', form.value.stock);
        if(form.value.category_id) formData.append('category_id', form.value.category_id);
        if(form.value.brand_id) formData.append('brand_id', form.value.brand_id);
        if(form.value.description) formData.append('description', form.value.description);
        
        if (form.value.image) {
            formData.append('image', form.value.image);
        }

        if (isEditing.value) {
            formData.append('_method', 'PUT');
            await api.post(`/admin/products/${form.value.id}`, formData, {
                 headers: { 'Content-Type': 'multipart/form-data' }
            });
        } else {
            await api.post('/admin/products', formData, {
                 headers: { 'Content-Type': 'multipart/form-data' }
            });
        }
        showModal.value = false;
        fetchProducts();
    } catch (e) {
        alert("Error al guardar: " + (e.response?.data?.message || e.message));
    } finally {
        processing.value = false;
    }
};

const deleteProduct = async (id) => {
    if(!confirm('¿Eliminar producto?')) return;
    await api.delete(`/admin/products/${id}`);
    fetchProducts();
};

onMounted(() => {
    fetchProducts();
});
</script>
