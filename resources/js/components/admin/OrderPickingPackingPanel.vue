<template>
    <section class="mt-6 border-t pt-6 space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-xl font-bold text-gray-800">Picking y packing</h3>
                <p class="text-sm text-gray-500">Confirmación manual producto por producto</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Registro manual</span>
        </div>

        <div v-if="!summary?.available" class="rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            {{ summary?.message || 'La operación de picking y packing aún no está disponible.' }}
        </div>
        <div v-else-if="summary.legacy" class="rounded bg-gray-50 p-4 text-sm text-gray-600">
            Pedido heredado finalizado. No requiere registros retroactivos de picking o packing.
        </div>
        <template v-else>
            <div class="grid gap-4 lg:grid-cols-2">
                <OperationCard title="Picking" :operation="summary.picking" :progress="summary.picking.progress">
                    <button v-if="summary.actions.start_picking" :disabled="busy" @click="confirmTransition('picking-start')" class="btn-primary">Iniciar picking</button>
                    <button v-if="summary.actions.complete_picking" :disabled="busy" @click="confirmTransition('picking-complete')" class="btn-success">Completar picking</button>
                </OperationCard>
                <OperationCard title="Packing" :operation="summary.packing" :progress="summary.packing.progress">
                    <button v-if="summary.actions.start_packing" :disabled="busy" @click="confirmTransition('packing-start')" class="btn-primary">Iniciar packing</button>
                    <button v-if="summary.actions.complete_packing" :disabled="busy" @click="confirmTransition('packing-complete')" class="btn-success">Completar packing</button>
                </OperationCard>
            </div>

            <div v-if="!summary.items?.length" class="rounded bg-gray-50 p-6 text-center text-gray-500">No existen productos para preparar.</div>
            <div v-else class="space-y-3">
                <article v-for="item in summary.items" :key="item.id" class="rounded-lg border p-4">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <img v-if="item.image_url && !brokenImages[item.id]" :src="item.image_url" :alt="item.product_name" @error="brokenImages[item.id]=true" class="h-14 w-14 rounded border object-cover">
                            <div v-else class="flex h-14 w-14 shrink-0 items-center justify-center rounded border bg-gray-100 text-gray-400"><i class="pi pi-image"></i></div>
                            <div class="min-w-0">
                                <p class="truncate font-semibold">{{ item.product_name }}</p>
                                <p class="text-xs text-gray-500">SKU {{ item.product_sku || '—' }}<span v-if="item.product_presentation"> · {{ item.product_presentation }}</span></p>
                                <p class="text-xs text-gray-500">{{ item.warehouse?.name || 'Almacén histórico' }}<span v-if="item.warehouse?.branch?.name"> — {{ item.warehouse.branch.name }}</span></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3 text-center text-sm">
                            <Metric label="Solicitado" :value="item.ordered_quantity" />
                            <Metric label="Recogido" :value="item.picked_quantity" />
                            <Metric label="Empacado" :value="item.packed_quantity" />
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <div class="rounded bg-emerald-50 p-3">
                            <label class="text-xs font-semibold text-emerald-800">Cantidad recogida · pendiente {{ item.pending_picking }}</label>
                            <div class="mt-2 flex gap-2">
                                <input v-model.number="pickedInputs[item.id]" :disabled="!summary.actions.update_picking || busy" type="number" min="0" :max="item.ordered_quantity" class="min-w-0 flex-1 rounded border px-3 py-2">
                                <button :disabled="!summary.actions.update_picking || busy" @click="savePicked(item)" class="btn-primary">Confirmar</button>
                                <button :disabled="!summary.actions.update_picking || busy" @click="confirmFullPicked(item)" class="btn-muted" title="Confirmar cantidad completa"><i class="pi pi-check"></i></button>
                            </div>
                        </div>
                        <div class="rounded bg-blue-50 p-3">
                            <label class="text-xs font-semibold text-blue-800">Cantidad empacada · pendiente {{ item.pending_packing }}</label>
                            <div class="mt-2 flex gap-2">
                                <input v-model.number="packedInputs[item.id]" :disabled="!summary.actions.update_packing || busy" type="number" min="0" :max="item.picked_quantity" class="min-w-0 flex-1 rounded border px-3 py-2">
                                <button :disabled="!summary.actions.update_packing || busy" @click="savePacked(item)" class="btn-primary">Confirmar</button>
                                <button :disabled="!summary.actions.update_packing || busy" @click="confirmFullPacked(item)" class="btn-muted" title="Confirmar cantidad completa"><i class="pi pi-check"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                        <span>Método: {{ item.confirmation_method === 'manual' ? 'Manual' : item.confirmation_method }}</span>
                        <span v-if="item.last_operated_at">Última operación: {{ formatDateTime(item.last_operated_at) }} · {{ item.last_operated_by?.name || 'Usuario eliminado' }}</span>
                        <button v-if="summary.actions.report_incident" @click="openIncident(item)" class="font-semibold text-red-600 hover:underline">Reportar incidencia</button>
                    </div>
                </article>
            </div>

            <section class="rounded-lg border border-red-100 p-4">
                <div class="flex items-center justify-between gap-3">
                    <h4 class="font-bold">Incidencias</h4>
                    <button v-if="summary.actions.report_incident" @click="openIncident(null)" class="text-sm font-semibold text-red-600">Nueva incidencia</button>
                </div>
                <p v-if="!summary.incidents?.length" class="mt-3 text-sm text-gray-500">Sin incidencias registradas.</p>
                <div v-for="incident in summary.incidents" :key="incident.id" :class="incident.status==='open'?'border-red-300 bg-red-50':'border-gray-200 bg-gray-50'" class="mt-3 rounded border p-3 text-sm">
                    <div class="flex flex-wrap justify-between gap-2"><b>{{ incidentLabel(incident.type) }}</b><span :class="incident.status==='open'?'text-red-700':'text-green-700'" class="font-semibold">{{ incident.status==='open'?'Abierta':'Resuelta' }}</span></div>
                    <p class="mt-1">{{ incident.description }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ formatDateTime(incident.reported_at) }} · {{ incident.reporter?.name || 'Usuario eliminado' }}<span v-if="incident.affected_quantity !== null"> · cantidad {{ incident.affected_quantity }}</span></p>
                    <button v-if="incident.status==='open' && summary.actions.report_incident" @click="resolveIncident(incident)" class="mt-2 text-xs font-semibold text-green-700 hover:underline">Resolver incidencia</button>
                </div>
            </section>

            <details class="rounded border p-4">
                <summary class="cursor-pointer font-semibold">Historial de picking y packing ({{ summary.history?.length || 0 }})</summary>
                <div v-for="event in summary.history" :key="event.id" class="mt-3 border-l-2 border-slate-300 pl-3 text-sm">
                    <b>{{ eventLabel(event.event_type) }}</b>
                    <p class="text-xs text-gray-500">{{ formatDateTime(event.created_at) }} · {{ event.user?.name || 'Usuario eliminado' }} · {{ event.confirmation_method }}</p>
                    <p v-if="event.observation" class="text-gray-600">{{ event.observation }}</p>
                </div>
            </details>
        </template>

        <div v-if="incidentModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4">
            <form @submit.prevent="submitIncident" class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                <div class="flex justify-between"><h4 class="text-lg font-bold">Reportar incidencia</h4><button type="button" @click="incidentModal=false" class="text-2xl">&times;</button></div>
                <div class="mt-4 space-y-3">
                    <label class="block text-sm">Tipo<select v-model="incidentForm.type" class="mt-1 w-full rounded border p-2" required><option value="missing">Producto faltante</option><option value="damaged">Producto dañado</option><option value="quantity_mismatch">Diferencia de cantidad</option><option value="wrong_product">Producto incorrecto</option><option value="other">Otra</option></select></label>
                    <label class="block text-sm">Producto<select v-model="incidentForm.order_item_id" class="mt-1 w-full rounded border p-2"><option value="">Pedido completo</option><option v-for="item in summary.items" :key="item.id" :value="item.order_item_id">{{ item.product_name }}</option></select></label>
                    <label class="block text-sm">Cantidad afectada<input v-model.number="incidentForm.affected_quantity" type="number" min="0" class="mt-1 w-full rounded border p-2"></label>
                    <label class="block text-sm">Descripción<textarea v-model="incidentForm.description" required maxlength="1000" class="mt-1 h-24 w-full rounded border p-2"></textarea></label>
                </div>
                <div class="mt-5 flex justify-end gap-2"><button type="button" @click="incidentModal=false" class="btn-muted">Cancelar</button><button :disabled="busy" class="btn-danger">Registrar</button></div>
            </form>
        </div>
    </section>
</template>

<script setup>
import {defineComponent,h,reactive,ref,watch} from 'vue';
import api from '@/api';
import {formatDateTime} from '@/utils/dateTime';
import {useConfirm} from 'primevue/useconfirm';
import {useToast} from 'primevue/usetoast';

const props=defineProps({orderId:{type:Number,required:true},summary:{type:Object,required:true}});
const emit=defineEmits(['updated']);
const confirm=useConfirm(),toast=useToast(),busy=ref(false),brokenImages=reactive({}),pickedInputs=reactive({}),packedInputs=reactive({});
const incidentModal=ref(false),incidentForm=reactive({type:'missing',order_item_id:'',affected_quantity:null,description:'',idempotency_key:''});
const OperationCard=defineComponent({props:{title:String,operation:Object,progress:Number},setup(p,{slots}){return()=>h('div',{class:'rounded-lg border p-4'},[h('div',{class:'flex justify-between'},[h('h4',{class:'font-bold'},p.title),h('span',{class:'text-sm font-semibold'},statusLabel(p.operation?.status))]),h('div',{class:'mt-3 h-2 overflow-hidden rounded bg-gray-200'},[h('div',{class:'h-full bg-green-600',style:{width:`${p.progress||0}%`}})]),h('div',{class:'mt-2 text-xs text-gray-500'},p.operation?.started_at?`Inicio ${formatDateTime(p.operation.started_at)} · ${p.operation.started_by?.name||'Usuario eliminado'}`:'Sin iniciar'),p.operation?.completed_at?h('div',{class:'text-xs text-gray-500'},`Fin ${formatDateTime(p.operation.completed_at)} · ${p.operation.completed_by?.name||'Usuario eliminado'}`):null,h('div',{class:'mt-3 flex gap-2'},slots.default?.())])}});
const Metric=defineComponent({props:{label:String,value:[String,Number]},setup:p=>()=>h('div',{class:'rounded bg-gray-50 px-3 py-2'},[h('div',{class:'text-xs text-gray-500'},p.label),h('b',String(p.value))])});

watch(()=>props.summary?.items,(items=[])=>items.forEach(item=>{pickedInputs[item.id]=item.picked_quantity;packedInputs[item.id]=item.packed_quantity}),{immediate:true,deep:true});
const statusLabel=s=>({pending:'Pendiente',in_progress:'En curso',completed:'Completado'}[s]||s||'—');
const incidentLabel=t=>({missing:'Producto faltante',damaged:'Producto dañado',quantity_mismatch:'Diferencia de cantidad',wrong_product:'Producto incorrecto',other:'Otra incidencia'}[t]||t);
const eventLabel=e=>({picking_started:'Picking iniciado',picked_quantity_updated:'Cantidad recogida actualizada',picking_completed:'Picking completado',packing_started:'Packing iniciado',packed_quantity_updated:'Cantidad empacada actualizada',packing_completed:'Packing completado',incident_reported:'Incidencia reportada',incident_resolved:'Incidencia resuelta',operation_canceled:'Operación cancelada'}[e]||e);
const endpoint=a=>({
    'picking-start':`/admin/orders/${props.orderId}/picking/start`, 'picking-complete':`/admin/orders/${props.orderId}/picking/complete`,
    'packing-start':`/admin/orders/${props.orderId}/packing/start`, 'packing-complete':`/admin/orders/${props.orderId}/packing/complete`,
}[a]);
function confirmTransition(action){const labels={'picking-start':'¿Iniciar el picking manual?','picking-complete':'¿Confirmar que el picking está completo?','packing-start':'¿Iniciar el packing manual?','packing-complete':'¿Confirmar que el packing está completo?'};confirm.require({message:labels[action],header:'Confirmación operativa',icon:'pi pi-check-circle',accept:()=>post(endpoint(action),{})})}
async function post(url,payload,method='post'){busy.value=true;try{const{data}=await api[method](url,payload);emit('updated',data);toast.add({severity:'success',summary:'Operación registrada',life:2200})}catch(e){showError(e)}finally{busy.value=false}}
function savePicked(item){return post(`/admin/orders/${props.orderId}/picking/items/${item.order_item_id}`,{picked_quantity:pickedInputs[item.id]},'patch')}
function savePacked(item){return post(`/admin/orders/${props.orderId}/packing/items/${item.order_item_id}`,{packed_quantity:packedInputs[item.id]},'patch')}
function confirmFullPicked(item){pickedInputs[item.id]=item.ordered_quantity;savePicked(item)}
function confirmFullPacked(item){packedInputs[item.id]=item.picked_quantity;savePacked(item)}
function openIncident(item){incidentForm.type='missing';incidentForm.order_item_id=item?.order_item_id||'';incidentForm.affected_quantity=null;incidentForm.description='';incidentForm.idempotency_key=globalThis.crypto?.randomUUID?.()||`${Date.now()}-${Math.random()}`;incidentModal.value=true}
async function submitIncident(){await post(`/admin/orders/${props.orderId}/incidents`,{...incidentForm,order_item_id:incidentForm.order_item_id||null,affected_quantity:incidentForm.affected_quantity===''?null:incidentForm.affected_quantity});incidentModal.value=false}
function resolveIncident(incident){const observation=window.prompt('Observación obligatoria de resolución:');if(!observation)return;post(`/admin/orders/${props.orderId}/incidents/${incident.id}/resolve`,{observation},'patch')}
function showError(e){toast.add({severity:'error',summary:'Operación no completada',detail:e.response?.data?.message||Object.values(e.response?.data?.errors||{})[0]?.[0]||e.message,life:4500})}
</script>

<style scoped>
.btn-primary{@apply rounded bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50}.btn-success{@apply rounded bg-green-600 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50}.btn-muted{@apply rounded bg-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50}.btn-danger{@apply rounded bg-red-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50}
</style>
