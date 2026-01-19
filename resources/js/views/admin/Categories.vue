<template>
    <AdminLayout>
        <div class="container mx-auto px-6 py-8">
            <div class="flex justify-between items-center mb-6">
                 <h3 class="text-gray-700 text-3xl font-medium">Gestión de Categorías</h3>
                 <button @click="openModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                     <i class="pi pi-plus mr-2"></i> Nueva Categoría
                 </button>
            </div>

            <div class="bg-white shadow-md rounded-lg overflow-hidden">
                <table class="min-w-full leading-normal">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Imagen</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Nombre</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Productos</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="category in categories" :key="category.id">
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <img :src="category.image || 'https://via.placeholder.com/150'" class="h-10 w-10 object-cover rounded-full">
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <p class="text-gray-900 whitespace-no-wrap font-bold">{{ category.name }}</p>
                            </td>
                             <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">
                                {{ category.products_count }}
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">
                                <button @click="openModal(category)" class="text-blue-600 hover:text-blue-900 mr-4"><i class="pi pi-pencil"></i></button>
                                <button @click="deleteCategory(category.id)" class="text-red-600 hover:text-red-900"><i class="pi pi-trash"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Modal -->
            <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center bg-black bg-opacity-50">
                <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
                    <h3 class="text-xl font-bold mb-4">{{ isEditing ? 'Editar Categoría' : 'Nueva Categoría' }}</h3>
                    <form @submit.prevent="submitForm">
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-bold mb-2">Nombre</label>
                            <input v-model="form.name" type="text" class="w-full border rounded px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" required>
                        </div>
                        <div class="mb-6">
                            <label class="block text-gray-700 text-sm font-bold mb-2">Imagen</label>
                            <input type="file" @change="handleFileUpload" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                             <p v-if="isEditing && !form.image" class="text-xs text-gray-500 mt-1">Deja vacío para mantener la actual.</p>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
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

const categories = ref([]);
const showModal = ref(false);
const isEditing = ref(false);
const processing = ref(false);
const form = ref({
    id: null,
    name: '',
    image: null
});

const fetchCategories = async () => {
    const response = await api.get('/admin/categories');
    categories.value = response.data;
};

const openModal = (category = null) => {
    isEditing.value = !!category;
    if (category) {
        form.value = { ...category, image: null };
    } else {
        form.value = { id: null, name: '', image: null };
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
        if (form.value.image) {
            formData.append('image', form.value.image);
        }

        if (isEditing.value) {
            formData.append('_method', 'PUT');
            await api.post(`/admin/categories/${form.value.id}`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
        } else {
            await api.post('/admin/categories', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
        }
        
        showModal.value = false;
        fetchCategories();
        toast.add({ severity: 'success', summary: 'Éxito', detail: 'Categoría guardada', life: 3000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || e.message, life: 3000 });
    } finally {
        processing.value = false;
    }
};

const deleteCategory = (id) => {
    confirm.require({
        message: '¿Eliminar categoría?',
        header: 'Confirmación',
        icon: 'pi pi-exclamation-triangle',
        accept: async () => {
             try {
                await api.delete(`/admin/categories/${id}`);
                fetchCategories();
                toast.add({ severity: 'success', summary: 'Eliminado', detail: 'Categoría eliminada', life: 3000 });
            } catch (e) {
                toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || e.message, life: 3000 });
            }
        }
    });
};

onMounted(() => {
    fetchCategories();
});
</script>
