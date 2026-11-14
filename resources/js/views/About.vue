<template>
    <AppLayout>
        <div class="about-page">
            <section class="about-hero relative isolate overflow-hidden bg-gradient-to-br from-blue-950 via-blue-900 to-blue-700 text-white" aria-labelledby="about-title">
                <div class="about-hero-decoration pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden="true">
                    <div class="about-hero-orb about-hero-orb-one"></div>
                    <div class="about-hero-orb about-hero-orb-two"></div>
                    <div class="about-hero-grid"></div>
                </div>
                <div class="site-container about-container relative z-10 py-8 sm:py-9 lg:py-10">
                    <p class="mb-3 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.16em] text-blue-200 sm:text-sm">
                        <i class="pi pi-compass" aria-hidden="true"></i>
                        Conoce LubriStore
                    </p>
                    <h1 id="about-title" class="max-w-3xl text-3xl font-black leading-tight tracking-tight sm:text-4xl lg:text-5xl">Sobre Nosotros</h1>
                    <p class="mt-4 max-w-2xl text-base leading-relaxed text-blue-100 sm:text-lg">
                        Productos para acompañar el cuidado y mantenimiento de tu vehículo.
                    </p>
                </div>
            </section>

            <section class="site-container about-container py-5 sm:py-6 lg:py-8" aria-labelledby="about-story-title">
                <div class="grid items-center gap-9 lg:grid-cols-2 lg:gap-14">
                    <figure class="about-illustration m-0 overflow-hidden rounded-3xl border border-blue-100 bg-slate-100 shadow-sm" role="img" aria-labelledby="about-illustration-title">
                        <svg class="block h-auto w-full" viewBox="0 0 640 460" width="640" height="460" xmlns="http://www.w3.org/2000/svg" aria-labelledby="about-illustration-title">
                            <title id="about-illustration-title">Ilustración conceptual de lubricación y cuidado automotriz</title>
                            <defs>
                                <linearGradient id="about-background" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0" stop-color="#eff6ff" />
                                    <stop offset="1" stop-color="#dbeafe" />
                                </linearGradient>
                                <linearGradient id="about-drop" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0" stop-color="#60a5fa" />
                                    <stop offset="1" stop-color="#1d4ed8" />
                                </linearGradient>
                            </defs>
                            <rect width="640" height="460" fill="url(#about-background)" />
                            <circle cx="505" cy="104" r="92" fill="#bfdbfe" opacity="0.65" />
                            <circle cx="120" cy="390" r="130" fill="#dbeafe" />
                            <path d="M0 336c91-36 163-34 246 4 75 34 149 31 231-8 58-28 110-31 163-16v144H0V336Z" fill="#fff" opacity="0.72" />
                            <g fill="none" stroke="#93c5fd" stroke-linecap="round" stroke-width="3" opacity="0.8">
                                <path d="M64 99h100M64 119h66M478 300h102M516 320h64" />
                                <circle cx="500" cy="170" r="34" />
                                <circle cx="500" cy="170" r="16" />
                                <path d="m500 125 8 10-8 10-8-10 8-10Zm45 45-10 8-10-8 10-8 10 8Zm-45 45-8-10 8-10 8 10-8 10Zm-45-45 10-8 10 8-10 8-10-8Z" />
                            </g>
                            <g transform="translate(206 91)">
                                <path d="M114 18c-7 20-31 49-31 70a31 31 0 1 0 62 0c0-21-24-50-31-70Z" fill="url(#about-drop)" />
                                <path d="M107 88c2 9 8 14 17 16" fill="none" stroke="#dbeafe" stroke-linecap="round" stroke-width="6" />
                                <rect x="54" y="151" width="120" height="151" rx="23" fill="#fff" stroke="#bfdbfe" stroke-width="4" />
                                <path d="M82 151v-19a32 32 0 0 1 64 0v19" fill="none" stroke="#1e40af" stroke-linecap="round" stroke-width="12" />
                                <path d="M78 204h72M78 226h52" stroke="#93c5fd" stroke-linecap="round" stroke-width="8" />
                                <circle cx="114" cy="267" r="13" fill="#2563eb" />
                            </g>
                            <g fill="#1e3a8a" opacity="0.8">
                                <circle cx="164" cy="180" r="5" />
                                <circle cx="445" cy="245" r="7" />
                                <circle cx="390" cy="104" r="4" />
                            </g>
                        </svg>
                        <figcaption class="border-t border-blue-100 bg-white px-4 py-2 text-center text-xs text-slate-600">
                            Ilustración conceptual; no representa una instalación real.
                        </figcaption>
                    </figure>

                    <div class="max-w-2xl">
                        <p class="mb-3 text-sm font-bold uppercase tracking-[0.14em] text-blue-700">Nuestra historia</p>
                        <h2 id="about-story-title" class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">Un espacio para el cuidado automotriz</h2>
                        <div class="mt-5 space-y-4 text-base leading-7 text-slate-600">
                            <p>LubriStore reúne lubricantes y productos orientados al cuidado y mantenimiento de vehículos.</p>
                            <p>El mantenimiento preventivo ayuda a cuidar el funcionamiento del vehículo. Por eso, invitamos a revisar la descripción y las especificaciones disponibles en cada producto antes de elegir.</p>
                        </div>
                        <router-link :to="{ name: 'Catalog' }" class="about-inline-link mt-7 inline-flex min-h-11 items-center gap-2 rounded-lg font-bold text-blue-800">
                            Explorar el catálogo
                            <i class="pi pi-arrow-right text-sm" aria-hidden="true"></i>
                        </router-link>
                    </div>
                </div>
            </section>

            <section class="bg-slate-50 py-12 sm:py-16" aria-labelledby="about-values-title">
                <div class="site-container about-container py-5 sm:py-6 lg:py-8">
                    <div class="mx-auto max-w-2xl text-center">
                        <p class="text-sm font-bold uppercase tracking-[0.14em] text-blue-700">Lo que nos orienta</p>
                        <h2 id="about-values-title" class="mt-2 text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">Nuestros valores</h2>
                    </div>
                    <ul class="mt-8 grid list-none grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3 lg:gap-5">
                        <li v-for="value in values" :key="value.title" class="about-value-card rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                            <span class="about-value-icon" aria-hidden="true"><i :class="value.icon"></i></span>
                            <h3 class="mt-4 text-lg font-bold text-slate-900">{{ value.title }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ value.description }}</p>
                        </li>
                    </ul>
                </div>
            </section>

            <section class="site-container about-container py-5 sm:py-6 lg:py-8" aria-labelledby="about-cta-title">
                <div class="about-cta flex flex-col items-start justify-between gap-6 rounded-3xl bg-blue-950 px-5 py-7 text-white sm:px-8 sm:py-9 lg:flex-row lg:items-center lg:px-10">
                    <div class="max-w-2xl">
                        <p class="text-sm font-bold uppercase tracking-[0.14em] text-blue-200">Da el siguiente paso</p>
                        <h2 id="about-cta-title" class="mt-2 text-2xl font-black tracking-tight sm:text-3xl">Encuentra productos para el cuidado de tu vehículo</h2>
                        <p class="mt-3 text-sm leading-6 text-blue-100 sm:text-base">Explora el catálogo o contáctanos si tienes una consulta.</p>
                    </div>
                    <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                        <router-link :to="{ name: 'Catalog' }" class="about-cta-primary inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-white px-5 py-3 text-center text-sm font-bold text-blue-900 transition hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                            Explorar catálogo <i class="pi pi-arrow-right" aria-hidden="true"></i>
                        </router-link>
                        <router-link to="/contact" class="about-cta-secondary inline-flex min-h-12 items-center justify-center rounded-xl border border-blue-300 px-5 py-3 text-center text-sm font-bold text-white transition hover:bg-blue-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                            Contáctanos
                        </router-link>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<script setup>
import { getSiteSeoConfig, useSiteSeo } from '@/composables/useSiteSeo';
import AppLayout from '@/layouts/AppLayout.vue';

const seoConfig = getSiteSeoConfig();
const values = [
    {
        title: 'Calidad de producto',
        description: 'Consulta la descripción y las características disponibles en cada ficha.',
        icon: 'pi pi-check-circle',
    },
    {
        title: 'Orientación',
        description: 'Puedes comunicarte con nosotros a través de la página de contacto.',
        icon: 'pi pi-comments',
    },
    {
        title: 'Cuidado preventivo',
        description: 'Promovemos la importancia de revisar y mantener el vehículo.',
        icon: 'pi pi-wrench',
    },
];

useSiteSeo(() => ({
    title: `Sobre LubriStore | ${seoConfig.name}`,
    description: 'Conoce LubriStore y explora productos para el cuidado y mantenimiento automotriz.',
    robots: 'index,follow',
    canonicalPath: '/about',
}));
</script>

<style scoped>
.about-container {
    max-width: 82rem;
}

.about-hero {
    background-color: #172554;
    background-image: linear-gradient(120deg, #172554 0%, #1e3a8a 56%, #1d4ed8 100%);
    color: #fff;
}

.about-hero-decoration {
    z-index: 0;
}

.about-hero > .about-container {
    position: relative;
    z-index: 1;
}

.about-cta {
    background-color: #172554;
    color: #fff;
}

.about-hero-orb {
    position: absolute;
    border: 1px solid rgb(191 219 254 / 14%);
    border-radius: 9999px;
}

.about-hero-orb-one {
    top: -12rem;
    right: 4%;
    width: 27rem;
    height: 27rem;
}

.about-hero-orb-two {
    top: -8rem;
    right: 12%;
    width: 19rem;
    height: 19rem;
}

.about-hero-grid {
    position: absolute;
    inset: 0;
    background-image: linear-gradient(rgb(255 255 255 / 4%) 1px, transparent 1px), linear-gradient(90deg, rgb(255 255 255 / 4%) 1px, transparent 1px);
    background-position: center;
    background-size: 2.5rem 2.5rem;
    mask-image: linear-gradient(to right, transparent, black 70%);
}

.about-value-card {
    min-height: 11.5rem;
    transition: border-color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
}

.about-value-card:hover {
    transform: translateY(-2px);
    border-color: rgb(147 197 253);
    box-shadow: 0 12px 26px rgb(15 23 42 / 8%);
}

.about-value-icon {
    display: inline-flex;
    width: 2.75rem;
    height: 2.75rem;
    align-items: center;
    justify-content: center;
    border-radius: 0.875rem;
    background: rgb(239 246 255);
    color: rgb(29 78 216);
    font-size: 1.125rem;
}

.about-inline-link:focus-visible {
    outline: 2px solid rgb(29 78 216);
    outline-offset: 3px;
}

@media (prefers-reduced-motion: reduce) {
    .about-page *,
    .about-page *::before,
    .about-page *::after {
        scroll-behavior: auto !important;
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

@media (max-width: 360px) {
    .about-hero-orb-one {
        right: -15rem;
    }

    .about-hero-orb-two {
        right: -12rem;
    }
}
</style>
