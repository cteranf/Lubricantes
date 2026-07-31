<template>
  <AdminLayout>
    <div class="container mx-auto px-6 py-8">
      <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
        <h1 class="text-gray-700 text-3xl font-medium">Gestion de {{ title }}</h1>
        <button @click="openModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700"><i class="pi pi-plus mr-2"></i>Nueva {{ singular }}</button>
      </div>
      <div class="bg-white rounded-lg shadow p-4 mb-4 flex gap-3">
        <input v-model="search" @keyup.enter="load(1)" :placeholder="`Buscar ${singular.toLowerCase()} por nombre`" class="flex-1 border rounded px-3 py-2">
        <button @click="load(1)" class="px-4 py-2 bg-gray-800 text-white rounded">Buscar</button>
      </div>
      <div class="bg-white shadow rounded-lg overflow-x-auto">
        <div v-if="loading" class="p-8 text-center text-gray-500">Cargando...</div>
        <div v-else-if="loadError" class="p-8 text-center"><p class="text-red-600 mb-3">{{ loadError }}</p><button @click="load(page)" class="text-blue-600">Reintentar</button></div>
        <table v-else class="min-w-full">
          <thead><tr class="bg-gray-100 text-xs uppercase text-gray-600"><th class="p-4 text-left">Nombre</th><th class="p-4 text-center">Productos</th><th class="p-4 text-center">Estado</th><th class="p-4 text-center">Acciones</th></tr></thead>
          <tbody>
            <tr v-for="item in items" :key="item.id" class="border-t"><td class="p-4 font-semibold">{{ item.name }}</td><td class="p-4 text-center">{{ item.products_count }}</td><td class="p-4 text-center"><span :class="isActive(item) ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700'" class="px-2 py-1 rounded-full text-xs">{{ isActive(item) ? 'Activa' : 'Inactiva' }}</span></td><td class="p-4 text-center"><button @click="openModal(item)" class="text-blue-600 mr-4" title="Editar"><i class="pi pi-pencil"></i></button><button @click="confirmStatus(item)" :class="isActive(item) ? 'text-red-600' : 'text-green-600'" :title="isActive(item) ? 'Desactivar' : 'Activar'"><i :class="isActive(item) ? 'pi pi-ban' : 'pi pi-check-circle'"></i></button></td></tr>
            <tr v-if="!items.length"><td colspan="4" class="p-8 text-center text-gray-500">No hay registros.</td></tr>
          </tbody>
        </table>
      </div>
      <div v-if="lastPage > 1" class="flex justify-center gap-3 mt-4"><button :disabled="page===1" @click="load(page-1)" class="px-3 py-1 border rounded disabled:opacity-40">Anterior</button><span class="py-1">Pagina {{ page }} de {{ lastPage }}</span><button :disabled="page===lastPage" @click="load(page+1)" class="px-3 py-1 border rounded disabled:opacity-40">Siguiente</button></div>
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6"><h2 class="text-xl font-bold mb-4">{{ editing ? `Editar ${singular}` : `Nueva ${singular}` }}</h2><form @submit.prevent="save"><label class="block text-sm font-bold mb-2">Nombre</label><input v-model="form.name" class="w-full border rounded px-3 py-2" autofocus><p v-if="errors.name" class="text-red-600 text-xs mt-1">{{ errors.name[0] }}</p><div class="flex justify-end gap-3 mt-6"><button type="button" @click="showModal=false" class="px-4 py-2 bg-gray-200 rounded">Cancelar</button><button :disabled="processing" class="px-4 py-2 bg-blue-600 text-white rounded disabled:opacity-50">{{ processing ? 'Guardando...' : 'Guardar' }}</button></div></form></div>
      </div>
    </div>
  </AdminLayout>
</template>
<script setup>
import { onMounted, ref } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import api from '@/api';
const props=defineProps({ endpoint:{type:String,required:true}, title:{type:String,required:true}, singular:{type:String,required:true} });
const toast=useToast(), confirm=useConfirm();
const items=ref([]), search=ref(''), page=ref(1), lastPage=ref(1), loading=ref(false), loadError=ref(''), showModal=ref(false), editing=ref(false), processing=ref(false), form=ref({id:null,name:''}), errors=ref({});
const validId=id=>Number.isInteger(Number(id))&&Number(id)>0;
const isActive=item=>item.is_active !== false && Number(item.is_active) !== 0;
async function load(target=1){ loading.value=true; loadError.value=''; try{ const {data}=await api.get(props.endpoint,{params:{page:target,search:search.value||undefined}}); items.value=data.data; page.value=data.current_page; lastPage.value=data.last_page; }catch(e){ loadError.value=e.response?.data?.message||'No se pudo cargar la informacion.'; }finally{ loading.value=false; } }
function openModal(item=null){ if(item&&!validId(item.id)){ toast.add({severity:'error',summary:'Registro invalido',detail:'No se puede editar este registro.',life:3000}); if(import.meta.env.DEV) console.warn('Entidad sin id valido',item); return; } editing.value=!!item; form.value={id:item?.id||null,name:item?.name||''}; errors.value={}; showModal.value=true; }
async function save(){ if(editing.value&&!validId(form.value.id)) return openModal({}); processing.value=true; errors.value={}; try{ editing.value?await api.put(`${props.endpoint}/${form.value.id}`,{name:form.value.name}):await api.post(props.endpoint,{name:form.value.name}); showModal.value=false; toast.add({severity:'success',summary:'Guardado',detail:`${props.singular} guardada correctamente.`,life:2500}); await load(page.value); }catch(e){ errors.value=e.response?.status===422?e.response.data.errors:{}; toast.add({severity:'error',summary:'No se pudo guardar',detail:e.response?.data?.message||e.message,life:3500}); }finally{ processing.value=false; } }
function confirmStatus(item){ if(!validId(item?.id)){ toast.add({severity:'error',summary:'Registro invalido',detail:'No se envio ninguna solicitud.',life:3000}); return; } const next=!isActive(item); confirm.require({message:`¿Desea ${next?'activar':'desactivar'} ${item.name}?`,header:'Confirmacion',icon:'pi pi-exclamation-triangle',accept:()=>changeStatus(item,next)}); }
async function changeStatus(item,next){ try{ await api.patch(`${props.endpoint}/${item.id}/status`,{is_active:next}); toast.add({severity:'success',summary:'Estado actualizado',detail:`${props.singular} ${next?'activada':'desactivada'}.`,life:2500}); await load(page.value); }catch(e){ toast.add({severity:'error',summary:'No se pudo cambiar el estado',detail:e.response?.data?.message||e.message,life:4000}); } }
onMounted(()=>load());
</script>
