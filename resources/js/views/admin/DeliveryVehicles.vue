<template>
  <AdminLayout>
    <main class="mx-auto max-w-7xl space-y-6">
      <AdminPageHeader eyebrow="OPERACIÓN DE ENTREGA" title="Vehículos" title-id="vehicles-title" description="Administra vehículos habilitados para reparto propio.">
        <template #action><button class="primary" @click="open()"><i class="pi pi-plus mr-2"/>Nuevo vehículo</button></template>
      </AdminPageHeader>
      <section class="metric">Total de vehículos <strong>{{meta.total||0}}</strong><small>Resultado global del listado</small></section>
      <form class="panel" @submit.prevent="load(1)">
        <label>Buscar<input v-model.trim="search" class="field" placeholder="Código, placa o marca"></label>
        <label>Tipo<select v-model="type" class="field"><option value="">Todos</option><option v-for="v in types" :key="v" :value="v">{{v}}</option></select></label>
        <div><button class="primary">Aplicar</button><button class="secondary" type="button" @click="clear">Limpiar</button><button class="icon" type="button" title="Actualizar" aria-label="Actualizar vehículos" @click="load()"><i class="pi pi-refresh"/></button></div>
      </form>
      <section v-if="error" class="error" role="alert">{{error}} <button class="secondary" @click="load()">Reintentar</button></section>
      <section v-else class="overflow-hidden rounded-2xl bg-white shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[720px] text-sm">
            <thead><tr><th scope="col">Vehículo</th><th scope="col">Tipo</th><th scope="col">Entregas</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
            <tbody>
              <template v-if="loading"><tr v-for="n in 5" :key="n"><td v-for="c in 5" :key="c"><span class="skeleton"/></td></tr></template>
              <template v-else-if="items.length"><tr v-for="item in items" :key="item.id"><td><b>{{item.code}}</b><span class="block text-slate-500">{{[item.brand,item.model].filter(Boolean).join(' ')||'Sin descripción'}}</span></td><td>{{item.vehicle_type}}</td><td>{{item.active_deliveries_count||0}} activas · {{item.deliveries_count||0}} total</td><td><AdminStatusBadge :active="!!item.is_active" :active-label="item.is_available?'Disponible':'No disponible'" inactive-label="Inactivo"/></td><td><AdminIconButton icon="pi pi-pencil" label="Editar vehículo" @click="open(item)"/><AdminIconButton :icon="item.is_available?'pi pi-pause-circle':'pi pi-check-circle'" :label="item.is_available?'Marcar no disponible':'Marcar disponible'" tone="text-amber-700" :disabled="!item.is_active" @click="availability(item)"/><AdminIconButton :icon="item.is_active?'pi pi-ban':'pi pi-check-circle'" :label="item.is_active?'Desactivar':'Activar'" :tone="item.is_active?'text-red-700':'text-emerald-700'" @click="statusChange(item)"/></td></tr></template>
              <tr v-else><td colspan="5"><AdminEmptyState icon="pi pi-truck" title="No hay vehículos" description="No se encontraron registros para los filtros aplicados."/></td></tr>
            </tbody>
          </table>
        </div>
        <AdminPagination :page="meta.current_page||1" :last-page="meta.last_page||1" :summary="summary" :loading="loading" @change="load"/>
      </section>

      <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 p-4">
        <form class="modal max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="vehicle-modal-title" @submit.prevent="save">
          <header class="flex items-start gap-3 border-b border-slate-200 px-5 py-4 sm:px-6">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700"><i class="pi pi-truck" aria-hidden="true"/></span>
            <div class="min-w-0 flex-1">
              <h2 id="vehicle-modal-title" class="text-lg font-bold text-slate-900">{{ form.id ? 'Editar vehículo' : 'Nuevo vehículo' }}</h2>
              <p class="mt-1 text-sm text-slate-500">{{ form.id ? 'Actualiza la información operativa y vigencias del vehículo.' : 'Registra un vehículo habilitado para reparto propio.' }}</p>
            </div>
            <button type="button" class="modal-close" aria-label="Cerrar modal de vehículo" :disabled="saving" @click="modal=false"><i class="pi pi-times" aria-hidden="true"/></button>
          </header>

          <div class="space-y-6 p-5 sm:p-6">
            <section class="modal-section">
              <div><h3>Identificación</h3><p>Datos que permiten reconocer el vehículo dentro de la operación.</p></div>
              <div class="grid gap-4 sm:grid-cols-2">
                <label for="vehicle-code">Código <span aria-hidden="true">*</span><input id="vehicle-code" v-model="form.code" required class="field"></label>
                <label for="vehicle-plate-number">Placa<input id="vehicle-plate-number" v-model="form.plate_number" class="field"></label>
                <label for="vehicle-type">Tipo<select id="vehicle-type" v-model="form.vehicle_type" class="field"><option v-for="v in types" :key="v">{{v}}</option></select></label>
                <label for="vehicle-ownership-type">Propiedad<select id="vehicle-ownership-type" v-model="form.ownership_type" class="field"><option value="company">Empresa</option><option value="driver">Repartidor</option><option value="third_party">Tercero</option></select></label>
              </div>
            </section>
            <section class="modal-section">
              <div><h3>Características</h3><p>Información descriptiva del vehículo.</p></div>
              <div class="grid gap-4 sm:grid-cols-2">
                <label for="vehicle-brand">Marca<input id="vehicle-brand" v-model="form.brand" class="field"></label>
                <label for="vehicle-model">Modelo<input id="vehicle-model" v-model="form.model" class="field"></label>
                <label for="vehicle-year">Año<input id="vehicle-year" v-model="form.year" type="number" class="field"></label>
                <label for="vehicle-color">Color<input id="vehicle-color" v-model="form.color" class="field"></label>
              </div>
            </section>
            <section class="modal-section">
              <div><h3>Vigencias</h3><p>Fechas de los documentos operativos requeridos.</p></div>
              <div class="grid gap-4 sm:grid-cols-2">
                <label for="vehicle-soat-expires-at">Vencimiento de SOAT<input id="vehicle-soat-expires-at" v-model="form.soat_expires_at" type="date" class="field"></label>
                <label for="vehicle-inspection-expires-at">Vencimiento de revisión técnica<input id="vehicle-inspection-expires-at" v-model="form.technical_inspection_expires_at" type="date" class="field"></label>
              </div>
            </section>
            <section class="modal-section">
              <div><h3>Disponibilidad</h3><p>Controla si el vehículo puede asignarse a entregas.</p></div>
              <div class="grid gap-3 sm:grid-cols-2">
                <label class="check-field" for="vehicle-is-active"><input id="vehicle-is-active" v-model="form.is_active" type="checkbox"> <span><b>Activo</b><small>Habilita el vehículo en la operación.</small></span></label>
                <label class="check-field" for="vehicle-is-available"><input id="vehicle-is-available" v-model="form.is_available" type="checkbox"> <span><b>Disponible</b><small>Permite asignarlo a nuevas entregas.</small></span></label>
              </div>
            </section>
          </div>

          <footer class="modal-footer flex flex-col-reverse gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
            <button type="button" class="secondary" :disabled="saving" @click="modal=false">Cancelar</button>
            <button class="primary" :disabled="saving"><i :class="saving ? 'pi pi-spin pi-spinner mr-2' : 'pi pi-save mr-2'" aria-hidden="true"/>{{ saving ? 'Guardando…' : (form.id ? 'Guardar cambios' : 'Crear vehículo') }}</button>
          </footer>
        </form>
      </div>
    </main>
  </AdminLayout>
</template>

<script setup>
import{computed,onMounted,ref}from'vue';import AdminLayout from '@/layouts/AdminLayout.vue';import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';import AdminStatusBadge from '@/components/admin/AdminStatusBadge.vue';import AdminIconButton from '@/components/admin/AdminIconButton.vue';import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';import AdminPagination from '@/components/admin/AdminPagination.vue';import api from '@/api';import{useToast}from'primevue/usetoast';const toast=useToast(),items=ref([]),search=ref(''),type=ref(''),meta=ref({}),loading=ref(false),error=ref(''),modal=ref(false),saving=ref(false),form=ref({}),types=['motorcycle','car','van','truck','bicycle','other'];const empty=()=>({code:'',plate_number:'',vehicle_type:'motorcycle',ownership_type:'company',brand:'',model:'',year:'',color:'',soat_expires_at:'',technical_inspection_expires_at:'',notes:'',is_active:true,is_available:true});const msg=e=>e.response?.data?.message||Object.values(e.response?.data?.errors||{})[0]?.[0]||'No se pudo completar la operación.';const summary=computed(()=>meta.value.total?`Mostrando ${meta.value.from}–${meta.value.to} de ${meta.value.total}`:'Sin registros');async function load(page=meta.value.current_page||1){if(loading.value)return;loading.value=true;error.value='';try{const{data}=await api.get('/admin/delivery-vehicles',{params:{page,search:search.value||undefined,type:type.value||undefined}});items.value=data.data||[];meta.value=data}catch(e){error.value=msg(e)}finally{loading.value=false}}function clear(){search.value='';type.value='';load(1)}function open(i=null){form.value=i?{...i}:empty();modal.value=true}async function save(){saving.value=true;try{form.value.id?await api.put(`/admin/delivery-vehicles/${form.value.id}`,form.value):await api.post('/admin/delivery-vehicles',form.value);modal.value=false;load()}catch(e){toast.add({severity:'error',summary:'No se pudo guardar',detail:msg(e),life:4000})}finally{saving.value=false}}async function statusChange(i){try{await api.patch(`/admin/delivery-vehicles/${i.id}/status`,{is_active:!i.is_active});load()}catch(e){toast.add({severity:'warn',summary:'Operación rechazada',detail:msg(e),life:4000})}}async function availability(i){try{await api.patch(`/admin/delivery-vehicles/${i.id}/availability`,{is_available:!i.is_available});load()}catch(e){toast.add({severity:'warn',summary:'Operación rechazada',detail:msg(e),life:4000})}}onMounted(load);
</script>

<style scoped>
.metric,.panel{background:#fff;border-radius:1rem;padding:1rem;box-shadow:0 1px 2px #0f172a14}.metric strong,.metric small{display:block}.panel,.grid{display:grid;gap:.75rem}.panel{grid-template-columns:1fr 12rem auto}.field{display:block;width:100%;margin-top:.25rem;border:1px solid #cbd5e1;border-radius:.6rem;padding:.6rem}.primary,.secondary,.icon{min-height:2.5rem;border-radius:.55rem;padding:.5rem .8rem;font-weight:700}.primary{background:#2563eb;color:white}.secondary,.icon{border:1px solid #cbd5e1}.icon{width:2.5rem}.error{background:#fef2f2;padding:1rem;color:#991b1b}.modal-section{border:1px solid #e2e8f0;border-radius:.85rem;padding:1rem}.modal-section h3{font-weight:700;color:#0f172a}.modal-section p{margin:.15rem 0 .85rem;color:#64748b;font-size:.875rem}.check-field{display:flex;align-items:flex-start;gap:.7rem;border:1px solid #cbd5e1;border-radius:.65rem;padding:.75rem;cursor:pointer}.check-field input{margin-top:.2rem}.check-field b,.check-field small{display:block}.check-field small{margin-top:.15rem;color:#64748b}.modal-close{display:inline-flex;height:2.5rem;width:2.5rem;align-items:center;justify-content:center;border-radius:.6rem;color:#475569}.modal-close:hover{background:#f1f5f9}.modal-close:focus-visible,.check-field:focus-within{outline:2px solid #2563eb;outline-offset:2px}th,td{padding:1rem;border-bottom:1px solid #e2e8f0;text-align:left}thead{background:#f1f5f9}@media(max-width:640px){.panel,.grid{grid-template-columns:1fr}}
</style>
