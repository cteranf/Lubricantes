<template>
    <AdminLayout>
        <div class="container mx-auto px-6 py-8">
            <div class="flex justify-between items-center mb-6">
                 <h3 class="text-gray-700 text-3xl font-medium">Noticias y Blog</h3>
                 <button @click="openModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                     <i class="pi pi-plus mr-2"></i> Nueva Noticia
                 </button>
            </div>

            <div class="bg-white shadow-md rounded-lg overflow-hidden">
                <table class="min-w-full leading-normal">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Imagen</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Título</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Estado</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in newsList" :key="item.id">
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <img :src="item.image_path || 'https://via.placeholder.com/150'" class="h-16 w-16 object-cover rounded">
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                                <p class="text-gray-900 whitespace-no-wrap font-bold">{{ item.title }}</p>
                                <p class="text-gray-600 whitespace-no-wrap text-xs truncate w-64">{{ item.summary }}</p>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">
                                <span :class="item.is_active ? 'bg-green-200 text-green-900' : 'bg-red-200 text-red-900'" class="px-2 py-1 leading-tight rounded-full text-xs font-semibold">
                                    {{ item.is_active ? 'Publicado' : 'Borrador' }}
                                </span>
                            </td>
                            <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">
                                <button @click="openModal(item)" class="text-blue-600 hover:text-blue-900 mr-4"><i class="pi pi-pencil"></i></button>
                                <button @click="deleteNews(item.id)" class="text-red-600 hover:text-red-900"><i class="pi pi-trash"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Modal -->
            <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center bg-black bg-opacity-50">
                <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6">
                    <h3 class="text-xl font-bold mb-4">{{ isEditing ? 'Editar Noticia' : 'Nueva Noticia' }}</h3>
                    <form @submit.prevent="submitForm">
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-bold mb-2">Título</label>
                            <input v-model="form.title" type="text" class="w-full border rounded px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-bold mb-2">Resumen</label>
                            <textarea v-model="form.summary" rows="2" class="w-full border rounded px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" required></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-bold mb-2">Contenido</label>
                            <textarea v-model="form.content" rows="6" class="w-full border rounded px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                        </div>
                        
                         <div class="mb-4">
                             <label class="flex items-center">
                                <input v-model="form.is_active" type="checkbox" class="mr-2">
                                <span class="text-gray-700 text-sm font-bold">Publicado</span>
                             </label>
                        </div>

                        <div class="mb-6">
                            <label class="block text-gray-700 text-sm font-bold mb-2">Imagen Destacada</label>
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

const newsList = ref([]);
const showModal = ref(false);
const isEditing = ref(false);
const processing = ref(false);
const form = ref({
    id: null,
    title: '',
    summary: '',
    content: '',
    is_active: true,
    image: null
});

const fetchNews = async () => {
    try {
        const response = await api.get('/admin/news');
        newsList.value = response.data.data;
    } catch(e) {
        console.error(e);
    }
};

const openModal = (item = null) => {
    isEditing.value = !!item;
    if (item) {
        form.value = { ...item, image: null, is_active: !!item.is_active };
    } else {
        form.value = { id: null, title: '', summary: '', content: '', is_active: true, image: null };
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
        formData.append('title', form.value.title);
        formData.append('summary', form.value.summary);
        if(form.value.content) formData.append('content', form.value.content);
        formData.append('is_active', form.value.is_active ? 1 : 0);
        
        if (form.value.image) {
            formData.append('image', form.value.image);
        }

        if (isEditing.value) {
            formData.append('_method', 'PUT');
            await api.post(`/admin/news/${form.value.id}`, formData, {
                 headers: { 'Content-Type': 'multipart/form-data' }
            });
        } else {
            await api.post('/admin/news', formData, {
                 headers: { 'Content-Type': 'multipart/form-data' }
            });
        }
        
        showModal.value = false;
        fetchNews();
        toast.add({ severity: 'success', summary: 'Éxito', detail: 'Noticia guardada correctamente', life: 3000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || e.message, life: 3000 });
    } finally {
        processing.value = false;
    }
};

const deleteNews = (id) => {
    confirm.require({
        message: '¿Eliminar noticia?',
        header: 'Confirmación',
        icon: 'pi pi-exclamation-triangle',
        accept: async () => {
            try {
                await api.delete(`/admin/news/${id}`);
                fetchNews();
                toast.add({ severity: 'success', summary: 'Eliminado', detail: 'Noticia eliminada', life: 3000 });
            } catch (e) {
                toast.add({ severity: 'error', summary: 'Error', detail: e.message, life: 3000 });
            }
        }
    });
};

onMounted(() => {
    fetchNews();
});
</script>
