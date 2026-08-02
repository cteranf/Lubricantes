<template>
    <AppLayout>
        <main class="bg-slate-50 py-10 sm:py-14 lg:py-16">
            <div class="site-container">
                <nav class="mb-6 text-sm text-slate-500" aria-label="Migas de pan">
                    <router-link to="/" class="focus-ring rounded hover:text-blue-700">Inicio</router-link>
                    <span class="mx-2" aria-hidden="true">/</span><span aria-current="page">Contacto</span>
                </nav>

                <div class="mx-auto grid max-w-6xl gap-8 lg:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)] lg:gap-12">
                    <section class="rounded-2xl bg-slate-950 p-7 text-white shadow-xl sm:p-9">
                        <p class="eyebrow text-amber-300">Estamos para ayudarte</p>
                        <h1 class="text-3xl font-black tracking-tight sm:text-4xl">Envíanos tu consulta</h1>
                        <p class="mt-5 leading-7 text-slate-300">Envíanos tu consulta y nuestro equipo se comunicará contigo.</p>
                        <div class="mt-8 rounded-xl border border-white/15 bg-white/5 p-5">
                            <div class="flex items-start gap-3"><i class="pi pi-shield mt-1 text-xl text-blue-300" aria-hidden="true"></i><div><h2 class="font-bold">Uso responsable de tus datos</h2><p class="mt-1 text-sm leading-6 text-slate-300">Usaremos tus datos únicamente para responder esta consulta.</p></div></div>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8" aria-labelledby="contact-form-title">
                        <h2 id="contact-form-title" class="text-2xl font-black text-slate-950">Cuéntanos cómo podemos ayudarte</h2>
                        <p class="mt-2 text-sm text-slate-600">Los campos marcados con * son obligatorios.</p>

                        <div class="sr-only" aria-live="polite" aria-atomic="true">{{ liveMessage }}</div>
                        <div v-if="successMessage" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800" role="status"><i class="pi pi-check-circle mr-2" aria-hidden="true"></i>{{ successMessage }}</div>
                        <div v-if="generalError" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert"><p>{{ generalError }}</p><button v-if="contextError" type="button" class="mt-2 min-h-11 font-bold underline" @click="prepareFormContext">Reintentar preparación</button></div>

                        <form class="mt-7 space-y-5" novalidate @submit.prevent="submitForm">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="contact-name" class="mb-1.5 block text-sm font-bold text-slate-800">Nombre *</label>
                                    <input id="contact-name" ref="nameInput" v-model="form.name" type="text" maxlength="120" autocomplete="name" class="contact-input" :class="errors.name && 'contact-input-error'" :aria-invalid="Boolean(errors.name)" :aria-describedby="errors.name ? 'contact-name-error' : undefined">
                                    <p v-if="errors.name" id="contact-name-error" class="contact-error">{{ errors.name[0] }}</p>
                                </div>
                                <div>
                                    <label for="contact-email" class="mb-1.5 block text-sm font-bold text-slate-800">Correo *</label>
                                    <input id="contact-email" v-model="form.email" type="email" maxlength="190" autocomplete="email" class="contact-input" :class="errors.email && 'contact-input-error'" :aria-invalid="Boolean(errors.email)" :aria-describedby="errors.email ? 'contact-email-error' : undefined">
                                    <p v-if="errors.email" id="contact-email-error" class="contact-error">{{ errors.email[0] }}</p>
                                </div>
                            </div>

                            <div>
                                <label for="contact-phone" class="mb-1.5 block text-sm font-bold text-slate-800">Teléfono <span class="font-normal text-slate-500">(opcional)</span></label>
                                <input id="contact-phone" v-model="form.phone" type="tel" maxlength="30" autocomplete="tel" class="contact-input" :class="errors.phone && 'contact-input-error'" aria-describedby="contact-phone-help contact-phone-error" :aria-invalid="Boolean(errors.phone)">
                                <p id="contact-phone-help" class="mt-1.5 text-xs text-slate-500">Inclúyelo únicamente si deseas que podamos contactarte también por teléfono o WhatsApp.</p>
                                <p v-if="errors.phone" id="contact-phone-error" class="contact-error">{{ errors.phone[0] }}</p>
                            </div>

                            <div>
                                <label for="contact-subject" class="mb-1.5 block text-sm font-bold text-slate-800">Asunto *</label>
                                <input id="contact-subject" v-model="form.subject" type="text" maxlength="180" class="contact-input" :class="errors.subject && 'contact-input-error'" :aria-invalid="Boolean(errors.subject)" aria-describedby="contact-subject-count contact-subject-error">
                                <div class="mt-1.5 flex justify-between gap-3 text-xs text-slate-500"><span>Describe brevemente el motivo.</span><span id="contact-subject-count">{{ form.subject.length }}/180</span></div>
                                <p v-if="errors.subject" id="contact-subject-error" class="contact-error">{{ errors.subject[0] }}</p>
                            </div>

                            <div>
                                <label for="contact-message" class="mb-1.5 block text-sm font-bold text-slate-800">Mensaje *</label>
                                <textarea id="contact-message" v-model="form.message" rows="7" maxlength="5000" class="contact-input min-h-40 resize-y" :class="errors.message && 'contact-input-error'" :aria-invalid="Boolean(errors.message)" aria-describedby="contact-message-count contact-message-error"></textarea>
                                <div class="mt-1.5 flex justify-between gap-3 text-xs text-slate-500"><span>Mínimo 10 caracteres.</span><span id="contact-message-count">{{ form.message.length }}/5000</span></div>
                                <p v-if="errors.message" id="contact-message-error" class="contact-error">{{ errors.message[0] }}</p>
                            </div>

                            <div class="absolute -left-[10000px] h-px w-px overflow-hidden" aria-hidden="true">
                                <label for="contact-website">No completar este campo</label><input id="contact-website" v-model="form.website" type="text" tabindex="-1" autocomplete="off">
                            </div>

                            <button type="submit" class="btn-primary w-full sm:w-auto sm:min-w-52" :disabled="sending || contextLoading || contextError">
                                <i :class="sending ? 'pi pi-spin pi-spinner' : 'pi pi-send'" aria-hidden="true"></i>{{ sending ? 'Enviando…' : 'Enviar consulta' }}
                            </button>
                        </form>
                    </section>
                </div>
            </div>
        </main>
    </AppLayout>
</template>

<script setup>
import { nextTick, onMounted, reactive, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import api from '@/api';
import AppLayout from '@/layouts/AppLayout.vue';

const toast = useToast();
const emptyForm = () => ({ name: '', email: '', phone: '', subject: '', message: '', website: '' });
const form = reactive(emptyForm());
const errors = ref({});
const sending = ref(false);
const contextLoading = ref(true);
const contextError = ref(false);
const generalError = ref('');
const successMessage = ref('');
const liveMessage = ref('');
const submissionToken = ref('');
const formContext = ref(null);
const nameInput = ref(null);

const uuid = () => {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(value => value.toString(16).padStart(2, '0'));
    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
};

async function prepareFormContext() {
    contextLoading.value = true; contextError.value = false; generalError.value = '';
    submissionToken.value = uuid();
    try {
        const { data } = await api.get('/contact-inquiries/form-context', { params: { submission_token: submissionToken.value } });
        formContext.value = data;
    } catch {
        contextError.value = true;
        generalError.value = 'No pudimos preparar el formulario. Revisa tu conexión e inténtalo nuevamente.';
    } finally { contextLoading.value = false; }
}

function validateClient() {
    const next = {};
    if (form.name.trim().length < 2) next.name = ['Ingresa un nombre de al menos 2 caracteres.'];
    if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) next.email = ['Ingresa un correo válido.'];
    if (form.subject.trim().length < 3) next.subject = ['El asunto debe tener al menos 3 caracteres.'];
    if (form.message.trim().length < 10) next.message = ['El mensaje debe tener al menos 10 caracteres.'];
    errors.value = next;
    return Object.keys(next).length === 0;
}

async function submitForm() {
    if (sending.value || !validateClient()) { await nextTick(); document.querySelector('[aria-invalid="true"]')?.focus(); return; }
    if (!formContext.value) { generalError.value = 'El formulario todavía no está listo. Inténtalo nuevamente.'; return; }
    sending.value = true; errors.value = {}; generalError.value = ''; successMessage.value = ''; liveMessage.value = 'Enviando consulta.';
    try {
        const { data } = await api.post('/contact-inquiries', {
            ...form,
            submission_token: submissionToken.value,
            ...formContext.value,
        });
        Object.assign(form, emptyForm());
        successMessage.value = data.message;
        liveMessage.value = data.message;
        toast.add({ severity: 'success', summary: 'Consulta recibida', detail: data.message, life: 4000 });
        await prepareFormContext();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            if (errors.value.form_token) {
                generalError.value = errors.value.form_token[0];
                await prepareFormContext();
            }
        } else if (error.response?.status === 429) {
            generalError.value = 'Se realizaron demasiados intentos. Espera un momento antes de volver a enviar.';
        } else {
            generalError.value = 'No pudimos guardar tu consulta. Tus datos permanecen en el formulario para que puedas reintentar.';
        }
        liveMessage.value = generalError.value || 'Revisa los errores del formulario.';
        await nextTick(); document.querySelector('[aria-invalid="true"]')?.focus();
    } finally { sending.value = false; }
}

onMounted(prepareFormContext);
</script>

<style scoped>
.contact-input { width: 100%; min-height: 44px; border: 1px solid #cbd5e1; border-radius: .75rem; background: white; padding: .7rem .85rem; color: #0f172a; outline: none; transition: border-color .15s ease, box-shadow .15s ease; }
.contact-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgb(37 99 235 / .16); }
.contact-input-error { border-color: #dc2626; }
.contact-error { margin-top: .375rem; color: #b91c1c; font-size: .75rem; font-weight: 600; }
@media (prefers-reduced-motion: reduce) { .contact-input { transition: none; } }
</style>
