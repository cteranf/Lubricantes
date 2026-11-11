<template>
    <AppLayout>
        <main class="mx-auto flex min-h-[60vh] max-w-xl items-center px-4 py-12">
            <section class="w-full rounded-lg bg-white p-6 shadow-lg sm:p-8" aria-labelledby="verification-title">
                <h1
                    id="verification-title"
                    ref="heading"
                    tabindex="-1"
                    class="text-2xl font-bold text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-600"
                >
                    Verificación de correo
                </h1>

                <p class="mt-4 text-gray-700" role="status" aria-live="polite" aria-atomic="true">
                    {{ statusMessage }}
                </p>

                <div v-if="isConfirmationLink && status === 'awaiting_confirmation'" class="mt-6">
                    <button
                        type="button"
                        :disabled="confirming"
                        class="w-full rounded-md bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                        @click="confirmEmail"
                    >
                        {{ confirming ? 'Verificando…' : 'Confirmar correo' }}
                    </button>
                </div>

                <form class="mt-8 space-y-4" novalidate @submit.prevent="resendVerification">
                    <h2 class="text-lg font-semibold text-gray-900">Solicitar otro enlace</h2>
                    <div>
                        <label for="verification-email" class="block text-sm font-medium text-gray-700">Correo electrónico</label>
                        <input
                            id="verification-email"
                            v-model="resendEmail"
                            type="email"
                            inputmode="email"
                            autocomplete="email"
                            required
                            :disabled="resending"
                            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600 disabled:bg-gray-100"
                            @input="resendFeedback = ''"
                        >
                    </div>
                    <p v-if="resendFeedback" role="alert" aria-live="polite" class="text-sm text-gray-700">
                        {{ resendFeedback }}
                    </p>
                    <button
                        type="submit"
                        :disabled="resending"
                        class="w-full rounded-md border border-blue-700 px-4 py-3 font-semibold text-blue-800 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
                    >
                        {{ resending ? 'Enviando…' : 'Reenviar instrucciones' }}
                    </button>
                </form>

                <p class="mt-6 text-center">
                    <RouterLink to="/login" class="font-semibold text-blue-700 underline focus:outline-none focus:ring-2 focus:ring-blue-600">
                        Volver al inicio de sesión
                    </RouterLink>
                </p>
            </section>
        </main>
    </AppLayout>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import api from '@/api';
import AppLayout from '@/layouts/AppLayout.vue';

const route = useRoute();
const router = useRouter();
const heading = ref(null);
const status = ref(statusFromRoute());
const resendEmail = ref('');
const resendFeedback = ref('');
const confirming = ref(false);
const resending = ref(false);
const confirmationSequence = ref(0);
const resendSequence = ref(0);
const confirmationController = ref(null);
const resendController = ref(null);
let mounted = false;

const isConfirmationLink = computed(() => route.name === 'EmailVerificationLink');
const statusMessage = computed(() => ({
    pending: 'Si acabas de registrarte o tu cuenta aún no está verificada, revisa tu correo para continuar.',
    awaiting_confirmation: 'Abre este enlace solo si solicitaste verificar esta cuenta. Confirma para completar el proceso.',
    verifying: 'Estamos verificando tu correo. Espera un momento.',
    verified: 'Correo verificado. Ya puedes iniciar sesión.',
    invalid: 'El enlace no es válido o venció. Solicita uno nuevo para continuar.',
    resent: 'Si la cuenta puede verificarse, enviaremos instrucciones al correo indicado.',
    error: 'No pudimos completar la solicitud. Inténtalo nuevamente más tarde.',
})[status.value] || 'Si tu cuenta necesita verificación, revisa tu correo para continuar.');

function statusFromRoute() {
    if (route.name === 'EmailVerificationLink') return 'awaiting_confirmation';

    const requestedStatus = route.query.status;
    const allowedStatuses = ['pending', 'verified', 'invalid', 'resent', 'error'];

    return typeof requestedStatus === 'string' && allowedStatuses.includes(requestedStatus)
        ? requestedStatus
        : 'pending';
}

function requestIsCurrent(sequence, currentSequence) {
    return mounted && sequence === currentSequence.value;
}

async function replaceWithCleanStatus(nextStatus) {
    const allowedStatuses = ['verified', 'invalid', 'error'];
    const safeStatus = allowedStatuses.includes(nextStatus) ? nextStatus : 'error';

    await router.replace({ name: 'EmailVerification', query: { status: safeStatus } });
}

async function confirmEmail() {
    if (confirming.value || !isConfirmationLink.value) return;

    confirming.value = true;
    status.value = 'verifying';
    const sequence = ++confirmationSequence.value;
    const controller = new AbortController();
    confirmationController.value = controller;
    const signedUrl = window.location.href;

    try {
        const response = await fetch(signedUrl, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            credentials: 'omit',
            signal: controller.signal,
        });

        if (!requestIsCurrent(sequence, confirmationSequence)) return;

        let nextStatus = 'error';
        if (response.status === 403) {
            nextStatus = 'invalid';
        } else if (response.status === 200) {
            const result = await response.json().catch(() => null);
            if (!requestIsCurrent(sequence, confirmationSequence)) return;
            nextStatus = ['email_verified', 'email_already_verified'].includes(result?.code)
                ? 'verified'
                : 'invalid';
        }

        status.value = nextStatus;
        await replaceWithCleanStatus(nextStatus);
    } catch {
        if (!requestIsCurrent(sequence, confirmationSequence)) return;
        status.value = 'error';
        await replaceWithCleanStatus('error');
    } finally {
        if (requestIsCurrent(sequence, confirmationSequence)) {
            confirming.value = false;
            confirmationController.value = null;
        }
    }
}

async function resendVerification() {
    if (resending.value) return;

    const email = resendEmail.value.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        resendFeedback.value = 'Ingresa un correo electrónico válido.';
        return;
    }

    resending.value = true;
    resendFeedback.value = '';
    const sequence = ++resendSequence.value;
    const controller = new AbortController();
    resendController.value = controller;

    try {
        const response = await api.post('/auth/email/verification-notification', { email }, { signal: controller.signal });
        if (!requestIsCurrent(sequence, resendSequence)) return;

        if (response.status === 202) {
            status.value = 'resent';
            resendFeedback.value = 'Si la cuenta puede verificarse, enviaremos instrucciones al correo indicado.';
        } else {
            status.value = 'error';
            resendFeedback.value = 'No pudimos completar la solicitud. Inténtalo nuevamente más tarde.';
        }
    } catch (requestError) {
        if (!requestIsCurrent(sequence, resendSequence)) return;

        const responseStatus = requestError?.response?.status;
        status.value = 'error';
        resendFeedback.value = responseStatus === 422
            ? 'No pudimos validar la solicitud. Revisa el correo e inténtalo nuevamente.'
            : responseStatus === 429
                ? 'Has realizado varias solicitudes. Inténtalo nuevamente más tarde.'
                : 'No pudimos completar la solicitud. Inténtalo nuevamente más tarde.';
    } finally {
        if (requestIsCurrent(sequence, resendSequence)) {
            resending.value = false;
            resendController.value = null;
        }
    }
}

function invalidatePendingRequests() {
    confirmationSequence.value++;
    resendSequence.value++;
    confirmationController.value?.abort();
    resendController.value?.abort();
    confirmationController.value = null;
    resendController.value = null;
    confirming.value = false;
    resending.value = false;
}

watch(() => route.fullPath, () => {
    invalidatePendingRequests();
    status.value = statusFromRoute();
    resendFeedback.value = '';
});

onMounted(async () => {
    mounted = true;
    status.value = statusFromRoute();
    await nextTick();
    heading.value?.focus();
});

onBeforeUnmount(() => {
    mounted = false;
    invalidatePendingRequests();
});
</script>
