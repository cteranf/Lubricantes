<template>
    <AppLayout>
        <main class="bg-slate-50">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                <nav class="mb-6 flex min-w-0 items-center gap-2 overflow-hidden text-sm text-slate-600" aria-label="Migas de pan">
                    <router-link to="/" class="shrink-0 hover:text-blue-700">Inicio</router-link><i class="pi pi-angle-right text-xs" aria-hidden="true"></i>
                    <router-link :to="catalogPath" class="shrink-0 hover:text-blue-700">Catálogo</router-link>
                    <template v-if="product"><i class="pi pi-angle-right text-xs" aria-hidden="true"></i><span class="truncate" aria-current="page">{{ product.name }}</span></template>
                </nav>

                <section v-if="loading" class="grid gap-8 lg:grid-cols-2 lg:gap-14" aria-label="Cargando producto" aria-busy="true">
                    <Skeleton height="min(78vw, 34rem)" border-radius="1rem" />
                    <div><Skeleton width="30%" height="1rem" class="mb-5" /><Skeleton width="85%" height="3rem" class="mb-6" /><Skeleton width="36%" height="2.5rem" class="mb-8" /><Skeleton width="100%" height="10rem" class="mb-8" /><Skeleton height="3.5rem" border-radius="0.75rem" /></div>
                </section>

                <section v-else-if="notFound" class="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm" role="status">
                    <i class="pi pi-search mb-4 text-4xl text-slate-400" aria-hidden="true"></i><h1 class="text-2xl font-black text-slate-950">Producto no encontrado</h1><p class="mt-3 text-slate-600">El producto solicitado no existe o ya no está disponible.</p><router-link :to="catalogPath" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-blue-700 px-5 py-2.5 font-bold text-white hover:bg-blue-800">Volver al catálogo</router-link>
                </section>

                <section v-else-if="error" class="mx-auto max-w-xl rounded-2xl border border-red-200 bg-white p-8 text-center shadow-sm" role="alert">
                    <i class="pi pi-exclamation-circle mb-4 text-4xl text-red-500" aria-hidden="true"></i><h1 class="text-2xl font-black text-slate-950">No pudimos cargar el producto</h1><p class="mt-3 text-slate-600">{{ error }}</p><div class="mt-6 flex flex-wrap justify-center gap-3"><button type="button" class="min-h-11 rounded-xl bg-blue-700 px-5 py-2.5 font-bold text-white hover:bg-blue-800" @click="loadProduct">Reintentar</button><router-link :to="catalogPath" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 py-2.5 font-bold text-slate-700 hover:bg-slate-100">Ir al catálogo</router-link></div>
                </section>

                <template v-else-if="product">
                    <section class="grid items-start gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(22rem,0.9fr)] lg:gap-12">
                        <div class="min-w-0 lg:sticky lg:top-24">
                            <div class="group relative flex aspect-square items-center justify-center overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                                <button v-if="selectedImage && !failedImages.has(selectedImage.url)" ref="galleryTrigger" type="button" class="flex h-full w-full items-center justify-center rounded-3xl focus-visible:outline focus-visible:outline-4 focus-visible:outline-offset-2 focus-visible:outline-blue-600" :aria-label="`Ampliar imagen ${selectedImageIndex + 1} de ${images.length} de ${product.name}`" @click="openLightbox" @keydown.left.prevent="selectAdjacentImage(-1)" @keydown.right.prevent="selectAdjacentImage(1)">
                                    <img v-if="selectedImage && !failedImages.has(selectedImage.url)" :src="selectedImage.url" :srcset="productImageSrcset(selectedImage, ['medium', 'detail', 'detail_source', 'zoom'])" sizes="(max-width: 1024px) 100vw, 50vw" :width="selectedImageDimensions?.width" :height="selectedImageDimensions?.height" :alt="`${product.name}, imagen ${images.findIndex(item => item.id === selectedImage.id) + 1}`" class="h-full w-full object-contain p-5 sm:p-10" @error="markImageFailed(selectedImage.url)">
                                </button>
                                <div v-else class="flex flex-col items-center gap-3 text-slate-400"><i class="pi pi-image text-6xl" aria-hidden="true"></i><span class="text-sm font-semibold">Imagen no disponible</span></div>
                                <span v-if="images.length" class="pointer-events-none absolute bottom-3 right-3 rounded-full bg-slate-950/75 px-3 py-1.5 text-xs font-bold text-white" aria-live="polite">{{ selectedImageIndex + 1 }} / {{ images.length }}</span>
                                <button v-if="images.length > 1" type="button" class="absolute left-3 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-slate-900 shadow-md transition hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" aria-label="Imagen anterior" @click="selectAdjacentImage(-1)"><i class="pi pi-angle-left text-xl" aria-hidden="true"></i></button>
                                <button v-if="images.length > 1" type="button" class="absolute right-3 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-slate-900 shadow-md transition hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" aria-label="Imagen siguiente" @click="selectAdjacentImage(1)"><i class="pi pi-angle-right text-xl" aria-hidden="true"></i></button>
                            </div>
                            <div v-if="images.length > 1" class="mt-4 flex gap-3 overflow-x-auto pb-2" aria-label="Imágenes del producto">
                                <button v-for="(image, index) in images" :key="image.id || image.url" type="button" class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border-2 bg-white p-1 transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" :class="selectedImage?.id === image.id ? 'border-blue-700' : 'border-slate-200 hover:border-slate-400'" :aria-label="`Ver imagen ${index + 1}`" :aria-pressed="selectedImage?.id === image.id" @click="selectedImage = image"><img :src="image.url" :srcset="thumbnailSrcset(image)" sizes="80px" :width="productImageDimensions(image)?.width" :height="productImageDimensions(image)?.height" :alt="`Vista ${index + 1} de ${product.name}`" class="h-full w-full object-contain" loading="lazy"></button>
                            </div>
                        </div>

                        <div class="min-w-0 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7 lg:p-8">
                            <p v-if="product.brand?.name" class="text-xs font-black uppercase tracking-[0.18em] text-blue-700">{{ product.brand.name }}</p>
                            <h1 class="mt-2 text-3xl font-black leading-tight tracking-tight text-slate-950 sm:text-4xl">{{ product.name }}</h1>
                            <p v-if="product.sku" class="mt-3 font-mono text-xs font-medium tracking-wide text-slate-500">SKU: {{ product.sku }}</p>
                            <button v-if="reviewSummary?.enabled" type="button" class="mt-4 inline-flex min-h-9 items-center gap-2 rounded-lg text-left text-sm text-slate-700 underline-offset-4 hover:text-blue-800 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" aria-label="Ver opiniones de clientes" @click="openReviewsFromSummary"><span class="tracking-wide text-amber-500" aria-hidden="true">{{ reviewStars }}</span><span class="font-bold">{{ Number(reviewSummary.average || 0).toFixed(1) }}</span><span>({{ reviewSummary.total }} {{ reviewSummary.total === 1 ? 'opinión' : 'opiniones' }})</span></button>
                            <div class="mt-6 flex flex-wrap items-baseline gap-x-3 gap-y-1"><span v-if="hasDiscount" class="text-base text-slate-500 line-through">{{ formatPen(product.price) }}</span><span class="text-3xl font-black tracking-tight text-slate-950">{{ formatPen(currentPrice) }}</span><span v-if="hasDiscount" class="rounded-full bg-amber-300 px-3 py-1 text-sm font-black text-slate-950">-{{ discount }}%</span></div>

                            <div class="mt-5 flex items-start gap-3 rounded-2xl border p-4" :class="stock > 0 ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-100'">
                                <i class="mt-0.5 text-lg" :class="stock > 0 ? 'pi pi-check-circle text-emerald-700' : 'pi pi-times-circle text-slate-500'" aria-hidden="true"></i>
                                <div><p class="font-bold" :class="stock > 0 ? 'text-emerald-800' : 'text-slate-700'">{{ stock > 0 ? `${stock} unidades disponibles` : 'Producto agotado' }}</p><p v-if="currentInCart" class="mt-1 text-sm text-slate-600">Ya tienes {{ currentInCart }} en el carrito.</p></div>
                            </div>

                            <dl v-if="attributes.length" class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3"><div v-for="attribute in attributes" :key="attribute.label" class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200"><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-500">{{ attribute.label }}</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-900">{{ attribute.value }}</dd></div></dl>

                            <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                                <div class="flex min-h-12 items-center justify-between rounded-xl border border-slate-300 bg-white sm:w-36" aria-label="Cantidad">
                                    <button type="button" class="min-h-12 min-w-11 rounded-l-xl hover:bg-slate-100 disabled:text-slate-300" :disabled="qty <= 1 || remainingStock <= 0" aria-label="Reducir cantidad" @click="qty--"><i class="pi pi-minus" aria-hidden="true"></i></button><output class="min-w-8 text-center font-black">{{ qty }}</output><button type="button" class="min-h-12 min-w-11 rounded-r-xl hover:bg-slate-100 disabled:text-slate-300" :disabled="qty >= remainingStock" aria-label="Aumentar cantidad" @click="qty++"><i class="pi pi-plus" aria-hidden="true"></i></button>
                                </div>
                                <button type="button" class="min-h-12 flex-1 rounded-xl bg-blue-700 px-6 py-3 font-black text-white shadow-sm transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600" :disabled="remainingStock <= 0 || addingToCart" @click="addToCart($event)"><i :class="addingToCart ? 'pi pi-spin pi-spinner' : 'pi pi-cart-plus'" class="mr-2" aria-hidden="true"></i>{{ addingToCart ? 'Agregando…' : remainingStock > 0 ? 'Agregar al carrito' : 'No disponible' }}</button>
                            </div>
                            <a :href="whatsAppUrl" target="_blank" rel="noopener noreferrer" class="mt-3 flex min-h-12 w-full items-center justify-center rounded-xl border border-emerald-600 px-5 py-3 font-bold text-emerald-700 transition hover:bg-emerald-50"><i class="pi pi-whatsapp mr-2 text-lg" aria-hidden="true"></i>Consultar por WhatsApp</a>

                            <div class="mt-6 grid gap-3 sm:grid-cols-3" aria-label="Información de compra">
                                <div class="rounded-xl border border-slate-200 bg-white p-4"><i class="pi pi-truck text-xl text-blue-700" aria-hidden="true"></i><h2 class="mt-2 text-sm font-black text-slate-900">Envío o recojo</h2><p class="mt-1 text-xs leading-5 text-slate-600">Elige entrega o recojo durante el checkout.</p></div>
                                <div class="rounded-xl border border-slate-200 bg-white p-4"><i class="pi pi-shield text-xl text-blue-700" aria-hidden="true"></i><h2 class="mt-2 text-sm font-black text-slate-900">Compra validada</h2><p class="mt-1 text-xs leading-5 text-slate-600">Precio y disponibilidad se confirman al procesar el pedido.</p></div>
                                <div class="rounded-xl border border-slate-200 bg-white p-4"><i class="pi pi-comments text-xl text-blue-700" aria-hidden="true"></i><h2 class="mt-2 text-sm font-black text-slate-900">Asesoría</h2><p class="mt-1 text-xs leading-5 text-slate-600">Resuelve tus dudas del producto por WhatsApp.</p></div>
                            </div>
                        </div>
                    </section>

                    <section v-if="relatedProducts.length" class="mt-12" aria-labelledby="related-products-heading">
                        <div class="mb-5 flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-700">También te puede interesar</p><h2 id="related-products-heading" class="mt-1 text-2xl font-black text-slate-950 sm:text-3xl">Más de esta categoría</h2></div><router-link :to="relatedCategoryPath" class="min-h-10 rounded-lg px-3 py-2 text-sm font-bold text-blue-800 hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700">Ver categoría <i class="pi pi-arrow-right ml-1" aria-hidden="true"></i></router-link></div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:gap-5"><ProductCard v-for="related in relatedProducts" :key="related.id" :product="related" variant="catalog" /></div>
                    </section>

                    <section class="mt-10 grid gap-5" :class="product.specifications?.length ? 'lg:grid-cols-2' : 'lg:grid-cols-1'" aria-label="Información del producto">
                        <article class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="text-xl font-black text-slate-950">Descripción</h2><p class="mt-4 whitespace-pre-line leading-7 text-slate-600">{{ product.description || 'Este producto no tiene una descripción disponible.' }}</p></article>
                        <article v-if="product.specifications?.length" class="rounded-2xl border border-slate-200 bg-white p-6" aria-labelledby="product-specifications-heading">
                            <h2 id="product-specifications-heading" class="text-xl font-black text-slate-950">Especificaciones técnicas</h2>
                            <dl class="mt-4 divide-y divide-slate-200">
                                <div v-for="(specification, index) in product.specifications" :key="`${specification.name}-${index}`" class="grid gap-1 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] sm:gap-4">
                                    <dt class="font-semibold text-slate-700">{{ specification.name }}</dt>
                                    <dd class="whitespace-pre-line break-words text-slate-600">{{ specification.value }}</dd>
                                </div>
                            </dl>
                        </article>
                    </section>

                    <section v-if="reviewSummary?.enabled" class="mt-10 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="product-reviews-heading" aria-live="polite">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h2 id="product-reviews-heading" class="text-2xl font-black text-slate-950">Opiniones de clientes</h2>
                                <p class="mt-2 text-slate-700"><span aria-label="Promedio de calificación">{{ Number(reviewSummary.average || 0).toFixed(1) }} de 5 estrellas</span> · {{ reviewSummary.total }} opiniones</p>
                            </div>
                            <button type="button" class="min-h-11 rounded-xl border border-blue-700 px-4 py-2 font-bold text-blue-800 hover:bg-blue-50" :aria-expanded="reviewsExpanded" aria-controls="product-reviews-panel" @click="toggleReviews">{{ reviewsExpanded ? 'Ocultar opiniones' : `Mostrar opiniones (${reviewSummary.total})` }}</button>
                        </div>
                        <div v-if="reviewsExpanded" id="product-reviews-panel" class="mt-6 space-y-6">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="text-lg font-bold">Opiniones publicadas</h3>
                                <label class="flex items-center gap-2 text-sm font-semibold">Ordenar
                                    <select v-model="reviewSort" class="rounded-lg border border-slate-300 p-2" aria-label="Ordenar opiniones" @change="loadReviewPage(1)"><option value="newest">Más recientes</option><option value="highest">Mayor calificación</option><option value="lowest">Menor calificación</option></select>
                                </label>
                            </div>
                            <p v-if="reviewError" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900" role="alert">{{ reviewError }} <button type="button" class="ml-2 font-bold underline" @click="loadReviewPage(reviewMeta.current_page)">Reintentar</button></p>
                            <div v-if="reviewLoading" class="space-y-3" aria-busy="true" aria-label="Cargando opiniones"><Skeleton height="6rem" /><Skeleton height="6rem" /></div>
                            <div v-else-if="reviews.length" class="space-y-4">
                                <article v-for="review in reviews" :key="review.id" class="rounded-xl border border-slate-200 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-2"><div><h4 v-if="review.title" class="font-bold text-slate-900">{{ review.title }}</h4><p class="text-sm text-slate-600">{{ review.display_name }} <span v-if="review.verified_purchase" class="ml-1 rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800">Compra verificada</span></p></div><span :aria-label="`${review.rating} de 5 estrellas`" class="font-bold text-amber-600">{{ '★'.repeat(review.rating) }}<span class="sr-only">{{ review.rating }} de 5 estrellas</span></span></div>
                                    <p class="mt-3 whitespace-pre-wrap break-words leading-7 text-slate-700">{{ review.comment }}</p>
                                </article>
                                <div class="flex items-center justify-between gap-3"><button type="button" class="min-h-10 rounded-lg border px-3 disabled:opacity-50" :disabled="reviewMeta.current_page <= 1 || reviewLoading" @click="loadReviewPage(reviewMeta.current_page - 1)">Anterior</button><span class="text-sm text-slate-600">Página {{ reviewMeta.current_page }} de {{ reviewMeta.last_page }}</span><button type="button" class="min-h-10 rounded-lg border px-3 disabled:opacity-50" :disabled="reviewMeta.current_page >= reviewMeta.last_page || reviewLoading" @click="loadReviewPage(reviewMeta.current_page + 1)">Siguiente</button></div>
                            </div>
                            <p v-else-if="!reviewLoading" class="rounded-lg bg-slate-50 p-4 text-slate-600">Aún no hay opiniones publicadas para este producto.</p>

                            <div ref="reviewFocusTarget" tabindex="-1" class="border-t border-slate-200 pt-5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700">
                                <template v-if="eligibility?.existing_review">
                                    <h3 class="text-lg font-bold">Tu opinión</h3>
                                    <p class="mt-1 text-sm text-slate-600">Estado: {{ reviewStatusLabel(eligibility.existing_review.status) }}</p>
                                    <p v-if="eligibility.existing_review.rejection_reason" class="mt-2 rounded bg-amber-50 p-3 text-sm text-amber-900">Motivo: {{ eligibility.existing_review.rejection_reason }}</p>
                                    <p v-if="!eligibility.module_enabled" class="mt-2 text-sm text-slate-600">No se aceptan cambios en este momento.</p>
                                </template>
                                <h3 v-else class="text-lg font-bold">Comparte tu experiencia</h3>
                                <p v-if="eligibility?.reason === 'login_required'" class="mt-2 text-slate-600">Inicia sesión para consultar si puedes opinar. <router-link to="/login" class="font-bold text-blue-700 underline">Iniciar sesión</router-link></p>
                                <p v-else-if="eligibility && !eligibility.eligible && !eligibility.existing_review" class="mt-2 text-slate-600">{{ eligibilityMessage(eligibility.reason) }}</p>
                                <form v-if="eligibility?.eligible || (eligibility?.existing_review && eligibility.module_enabled)" class="mt-4 space-y-4" @submit.prevent="submitReview">
                                    <fieldset class="min-w-0">
                                        <legend id="review-rating-label" class="text-sm font-semibold">Calificación</legend>
                                        <Rating v-model="reviewRating" name="product-review-rating" :stars="5" :cancel="false" aria-labelledby="review-rating-label" />
                                    </fieldset>
                                    <label class="block text-sm font-semibold" for="review-title">Título (opcional)<input id="review-title" v-model="reviewForm.title" maxlength="120" class="mt-1 w-full rounded-lg border border-slate-300 p-3" autocomplete="off"><small class="mt-1 block text-slate-500">{{ reviewForm.title.length }} / 120</small></label>
                                    <label class="block text-sm font-semibold" for="review-comment">Comentario<textarea id="review-comment" v-model="reviewForm.comment" minlength="20" maxlength="1000" rows="5" required class="mt-1 w-full rounded-lg border border-slate-300 p-3" /><small class="mt-1 block text-slate-500">{{ reviewForm.comment.length }} / 1000; mínimo 20 caracteres.</small></label>
                                    <p v-if="reviewFormError" role="alert" class="text-sm text-red-700">{{ reviewFormError }}</p>
                                    <button type="submit" class="min-h-11 rounded-xl bg-blue-700 px-5 py-2 font-bold text-white disabled:opacity-50" :disabled="reviewSubmitting || reviewForm.rating < 1 || reviewForm.comment.trim().length < 20">{{ reviewSubmitting ? 'Enviando…' : eligibility.existing_review ? 'Actualizar opinión' : 'Enviar opinión' }}</button>
                                    <p class="text-xs text-slate-500">Las opiniones se revisan antes de publicarse.</p>
                                </form>
                            </div>
                        </div>
                    </section>
                </template>
            </div>
            <Dialog v-model:visible="lightboxVisible" modal :block-scroll="true" :dismissable-mask="true" :close-on-escape="true" :draggable="false" :breakpoints="{ '960px': '95vw' }" :style="{ width: 'min(72rem, 96vw)' }" :close-button-props="{ 'aria-label': 'Cerrar imagen ampliada' }" :pt="lightboxPassThrough" aria-label="Imagen ampliada del producto">
                <div v-if="selectedImage" tabindex="0" class="relative flex min-h-[45vh] max-h-[78vh] w-full items-center justify-center bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700 sm:min-h-[60vh]" @keydown.left.prevent="selectAdjacentImage(-1)" @keydown.right.prevent="selectAdjacentImage(1)">
                    <img v-if="!failedImages.has(selectedImage.url)" :src="selectedImage.url" :srcset="productImageSrcset(selectedImage, ['medium', 'detail', 'detail_source', 'zoom'])" sizes="(max-width: 768px) 94vw, 72rem" :width="selectedImageDimensions?.width" :height="selectedImageDimensions?.height" :alt="`${product?.name || 'Producto'}, imagen ${selectedImageIndex + 1}`" class="max-h-[78vh] max-w-full object-contain" @error="markImageFailed(selectedImage.url)">
                    <div v-else class="flex flex-col items-center gap-3 text-slate-500" role="status"><i class="pi pi-image text-5xl" aria-hidden="true"></i><span>Imagen no disponible</span></div>
                    <button v-if="images.length > 1" type="button" class="absolute left-2 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-slate-900 shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" aria-label="Imagen anterior" @click="selectAdjacentImage(-1)"><i class="pi pi-angle-left text-xl" aria-hidden="true"></i></button>
                    <button v-if="images.length > 1" type="button" class="absolute right-2 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-slate-900 shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-700" aria-label="Imagen siguiente" @click="selectAdjacentImage(1)"><i class="pi pi-angle-right text-xl" aria-hidden="true"></i></button>
                    <span class="absolute bottom-3 right-3 rounded-full bg-slate-950/75 px-3 py-1.5 text-xs font-bold text-white">{{ selectedImageIndex + 1 }} / {{ images.length }}</span>
                </div>
            </Dialog>
        </main>
    </AppLayout>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { getSiteSeoConfig, useSiteSeo } from '@/composables/useSiteSeo';
import Skeleton from 'primevue/skeleton';
import Rating from 'primevue/rating';
import Dialog from 'primevue/dialog';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/layouts/AppLayout.vue';
import ProductCard from '@/components/ProductCard.vue';
import api from '@/api';
import { useCartStore } from '@/stores/cart';
import { useAuthStore } from '@/stores/auth';
import { discountPercentage, formatPen, hasValidSale, productImageDimensions, productImageSrcset, productImageVariants, productPrice, resolveProductGallery, resolveProductImages } from '@/utils/productPresentation';

const route = useRoute();
const router = useRouter();
const seoConfig = getSiteSeoConfig();
const cartStore = useCartStore();
const authStore = useAuthStore();
const toast = useToast();
const product = ref(null);
const loading = ref(true);
const error = ref('');
const notFound = ref(false);
const qty = ref(1);
const selectedImage = ref(null);
const failedImages = ref(new Set());
const relatedProducts = ref([]);
const lightboxVisible = ref(false);
const lightboxPassThrough = { root: { class: 'w-[min(96vw,72rem)]' }, content: { class: 'p-2 sm:p-4' } };
let controller;
let relatedController;
let requestId = 0;
const reviewSummary = ref(null), reviewsExpanded = ref(false), reviews = ref([]), reviewLoading = ref(false), reviewError = ref('');
const reviewMeta = ref({ current_page: 1, last_page: 1, total: 0 });
const reviewSort = ref('newest'), eligibility = ref(null), reviewSubmitting = ref(false), reviewFormError = ref('');
const reviewForm = ref({ rating: 0, title: '', comment: '' });
const addingToCart = ref(false);
const reviewRating = computed({
    get: () => {
        const rating = Number(reviewForm.value.rating);
        return Number.isInteger(rating) && rating >= 1 && rating <= 5 ? rating : 0;
    },
    set: value => {
        const rating = Number(value);
        reviewForm.value.rating = Number.isInteger(rating) && rating >= 1 && rating <= 5 ? rating : 0;
    },
});
const reviewFocusTarget = ref(null);
let reviewSummaryController = null, reviewListController = null, reviewEligibilityController = null, reviewSequence = 0;
let reviewInitialLoadPromise = null, reviewInitialDataLoaded = false, reviewAutoOpenSequence = null;

const catalogPath = computed(() => sessionStorage.getItem('catalog:last-path') || '/catalog');
const images = computed(() => resolveProductGallery(product.value));
const selectedImageDimensions = computed(() => productImageDimensions(selectedImage.value));
const selectedImageIndex = computed(() => Math.max(0, images.value.findIndex(image => image.id === selectedImage.value?.id)));
const reviewStars = computed(() => {
    const fullStars = Math.max(0, Math.min(5, Math.round(Number(reviewSummary.value?.average || 0))));
    return `${'★'.repeat(fullStars)}${'☆'.repeat(5 - fullStars)}`;
});
const relatedCategoryPath = computed(() => product.value?.category?.slug ? `/catalog?category=${encodeURIComponent(product.value.category.slug)}` : '/catalog');
const thumbnailSrcset = image => productImageSrcset(image, ['thumbnail', 'card']);
const detailImage = value => {
    const gallery = resolveProductGallery(value);
    const image = gallery.find(item => item.is_primary) || gallery[0];
    if (!image) return null;
    const variants = productImageVariants(image);
    const preferred = ['detail', 'detail_source', 'zoom', 'medium', 'card', 'thumbnail']
        .map(key => variants.find(item => item.key === key)?.url).find(Boolean);
    return { ...image, url: preferred || image.url };
};
const isPublicProduct = value => value && typeof value === 'object'
    && (value.is_active === true || value.is_active === 1 || value.is_active === '1')
    && typeof value.name === 'string' && value.name.trim() !== ''
    && typeof value.slug === 'string' && value.slug.trim() !== '';
useSiteSeo(() => {
    if (loading.value || notFound.value || error.value || !isPublicProduct(product.value)) {
        return {
            title: `Producto no disponible | ${seoConfig.name}`,
            description: 'El producto solicitado no está disponible.',
            robots: 'noindex,nofollow',
            canonicalPath: null,
            type: 'website',
            image: null,
            breadcrumbs: [],
        };
    }
    const canonicalPath = `/product/${encodeURIComponent(String(product.value.slug || route.params.slug || ''))}`;
    return {
        title: `${product.value.name} | ${seoConfig.name}`,
        description: product.value.description || `Consulta los detalles y la disponibilidad de ${product.value.name} en ${seoConfig.name}.`,
        robots: 'index,follow',
        canonicalPath,
        type: 'product',
        image: images.value.find(item => item.is_primary)?.url || images.value[0]?.url || undefined,
        imageAlt: product.value.name,
        breadcrumbs: [{ name: 'Inicio', path: '/' }, { name: 'Catálogo', path: '/catalog' }, { name: product.value.name, path: canonicalPath }],
        product: {
            name: product.value.name,
            description: product.value.description || '',
            sku: product.value.sku,
            brand: product.value.brand?.name,
            images: images.value.map(item => item.url),
            price: product.value.price,
            sale_price: product.value.sale_price,
            stock: product.value.stock,
        },
    };
});
const stock = computed(() => Math.max(0, Number(product.value?.stock || 0)));
const currentInCart = computed(() => cartStore.items.find(item => item.product_id === product.value?.id)?.quantity || 0);
const remainingStock = computed(() => Math.max(0, stock.value - currentInCart.value));
const hasDiscount = computed(() => hasValidSale(product.value));
const currentPrice = computed(() => productPrice(product.value));
const discount = computed(() => discountPercentage(product.value));
const attributes = computed(() => [
    { label: 'Presentación', value: product.value?.presentation },
    { label: 'Viscosidad', value: product.value?.viscosity },
    { label: 'Tipo', value: product.value?.type },
].filter(item => typeof item.value === 'string' && item.value.trim()).filter((item, index, list) => list.findIndex(candidate => candidate.value.trim().toLocaleLowerCase() === item.value.trim().toLocaleLowerCase()) === index).slice(0, 3));
const whatsAppUrl = computed(() => {
    const phone = import.meta.env.VITE_WHATSAPP_PHONE || '51999999999';
    const parts = [`Hola, quisiera consultar por ${product.value?.name || 'este producto'}.`];
    if (product.value?.presentation) parts.push(`Presentación: ${product.value.presentation}.`);
    parts.push(`Enlace: ${window.location.href}`);
    return `https://wa.me/${phone}?text=${encodeURIComponent(parts.join(' '))}`;
});

const loadProduct = async () => {
    const slug = Array.isArray(route.params.slug) ? route.params.slug[0] : route.params.slug;
    controller?.abort();
    relatedController?.abort();
    relatedController = null;
    relatedProducts.value = [];
    lightboxVisible.value = false;
    resetProductReviews();
    const id = ++requestId;
    product.value = null; error.value = ''; notFound.value = false; loading.value = true;
    if (typeof slug !== 'string' || !slug.trim() || slug === 'undefined') { notFound.value = true; loading.value = false; return; }
    controller = new AbortController();
    try {
        const response = await api.get(`/products/${encodeURIComponent(slug)}`, { signal: controller.signal });
        if (id !== requestId) return;
        if (!isPublicProduct(response.data) || response.data.slug !== slug) {
            notFound.value = true;
            return;
        }
        product.value = response.data;
        void loadReviewSummary(id, slug);
        void loadRelatedProducts(id, slug, response.data);
        const firstImage = detailImage(response.data);
        selectedImage.value = firstImage; failedImages.value = new Set(); qty.value = 1;
    } catch (exception) {
        if (exception?.code === 'ERR_CANCELED' || id !== requestId) return;
        if (exception.response?.status === 404) notFound.value = true;
        else error.value = 'Ocurrió un problema al consultar el producto. Inténtalo nuevamente.';
    } finally { if (id === requestId) loading.value = false; }
};

async function loadRelatedProducts(sequence, slug, currentProduct) {
    const categorySlug = currentProduct?.category?.slug;
    if (typeof categorySlug !== 'string' || !categorySlug.trim()) return;

    const current = new AbortController();
    relatedController = current;
    try {
        const { data } = await api.get('/products', {
            params: { category: categorySlug, page: 1, sort: 'newest' },
            signal: current.signal,
        });
        if (current.signal.aborted || sequence !== requestId || product.value?.slug !== slug) return;

        const seen = new Set([String(currentProduct.id)]);
        relatedProducts.value = (Array.isArray(data?.data) ? data.data : [])
            .filter(candidate => {
                const id = String(candidate?.id ?? '');
                const publicCategory = candidate?.category?.slug === categorySlug;
                const publicActive = candidate?.is_active === true || candidate?.is_active === 1 || candidate?.is_active === '1';
                if (!id || seen.has(id) || !publicCategory || !publicActive) return false;
                seen.add(id);
                return true;
            })
            .slice(0, 4);
    } catch {
        if (!current.signal.aborted && sequence === requestId && product.value?.slug === slug) relatedProducts.value = [];
    } finally {
        if (relatedController === current) relatedController = null;
    }
}

function selectAdjacentImage(offset) {
    if (images.value.length < 2) return;
    const currentIndex = selectedImageIndex.value;
    const nextIndex = (currentIndex + offset + images.value.length) % images.value.length;
    selectedImage.value = images.value[nextIndex];
}

function openLightbox() {
    if (selectedImage.value && !failedImages.value.has(selectedImage.value.url)) lightboxVisible.value = true;
}

async function openReviewsFromSummary() {
    if (!reviewSummary.value?.enabled) return;
    if (!reviewsExpanded.value) {
        reviewsExpanded.value = true;
        await loadInitialReviewData();
    }
    await nextTick();
    reviewFocusTarget.value?.focus();
}

function resetProductReviews() {
    reviewSequence++;
    reviewSummaryController?.abort(); reviewListController?.abort(); reviewEligibilityController?.abort();
    reviewSummaryController = reviewListController = reviewEligibilityController = null;
    reviewInitialLoadPromise = null; reviewInitialDataLoaded = false; reviewAutoOpenSequence = null;
    reviewSummary.value = null; reviewsExpanded.value = false; reviews.value = []; eligibility.value = null;
    reviewMeta.value = { current_page: 1, last_page: 1, total: 0 }; reviewError.value = ''; reviewFormError.value = '';
    reviewForm.value = { rating: 0, title: '', comment: '' }; reviewLoading.value = false; reviewSubmitting.value = false;
}
async function loadReviewSummary(sequence, slug) {
    const controller = new AbortController(); reviewSummaryController = controller;
    try {
        const { data } = await api.get(`/products/${encodeURIComponent(slug)}/reviews/summary`, { signal: controller.signal });
        if (sequence === requestId && product.value?.slug === slug) {
            reviewSummary.value = data;
            await consumeReviewOpen(sequence, slug);
        }
    } catch {
        if (!controller.signal.aborted && sequence === requestId) {
            reviewSummary.value = { enabled: false };
            await consumeReviewOpen(sequence, slug);
        }
    }
}
async function toggleReviews() {
    if (reviewsExpanded.value) { reviewsExpanded.value = false; return; }
    reviewsExpanded.value = true;
    await loadInitialReviewData();
}
async function loadInitialReviewData() {
    if (reviewInitialDataLoaded) return;
    if (reviewInitialLoadPromise) return reviewInitialLoadPromise;
    const sequence = reviewSequence;
    reviewInitialLoadPromise = Promise.all([loadReviewEligibility(), loadReviewPage(1)]);
    try {
        await reviewInitialLoadPromise;
        if (sequence === reviewSequence) reviewInitialDataLoaded = !reviewFormError.value && !reviewError.value;
    } finally {
        if (sequence === reviewSequence) reviewInitialLoadPromise = null;
    }
}
async function consumeReviewOpen(sequence, slug) {
    if (route.query.reviews !== 'open' || reviewAutoOpenSequence === sequence) return;
    reviewAutoOpenSequence = sequence;
    const query = { ...route.query };
    delete query.reviews;
    void router.replace({ name: route.name, params: route.params, query, hash: route.hash }).catch(() => {});
    if (!reviewSummary.value?.enabled || sequence !== requestId || product.value?.slug !== slug) return;
    reviewsExpanded.value = true;
    await loadInitialReviewData();
    if (sequence === requestId && product.value?.slug === slug && reviewsExpanded.value) {
        await nextTick();
        reviewFocusTarget.value?.focus();
    }
}
async function loadReviewEligibility() {
    const slug = product.value?.slug; if (!slug) return;
    const sequence = reviewSequence; reviewEligibilityController?.abort();
    const current = new AbortController(); reviewEligibilityController = current;
    try {
        const { data } = await api.get(`/products/${encodeURIComponent(slug)}/review-eligibility`, { signal: current.signal });
        if (sequence !== reviewSequence || !reviewsExpanded.value) return;
        eligibility.value = data;
        if (data?.existing_review) reviewForm.value = { rating: Number(data.existing_review.rating), title: data.existing_review.title || '', comment: data.existing_review.comment || '' };
    } catch {
        if (!current.signal.aborted && sequence === reviewSequence) reviewFormError.value = 'No se pudo consultar la elegibilidad. Inténtalo nuevamente.';
    }
}
async function loadReviewPage(page = 1) {
    const slug = product.value?.slug; if (!slug) return;
    const sequence = reviewSequence; reviewListController?.abort();
    const current = new AbortController(); reviewListController = current; reviewLoading.value = true; reviewError.value = '';
    try {
        const { data } = await api.get(`/products/${encodeURIComponent(slug)}/reviews`, { params: { page, per_page: 10, sort: reviewSort.value }, signal: current.signal });
        if (sequence !== reviewSequence || !reviewsExpanded.value) return;
        reviews.value = Array.isArray(data?.data) ? data.data : [];
        reviewMeta.value = data?.meta || { current_page: 1, last_page: 1, total: 0 };
    } catch {
        if (!current.signal.aborted && sequence === reviewSequence) reviewError.value = 'No se pudieron cargar las opiniones. Inténtalo nuevamente.';
    } finally { if (sequence === reviewSequence) reviewLoading.value = false; }
}
async function submitReview() {
    if (reviewSubmitting.value || !eligibility.value || (!eligibility.value.eligible && !eligibility.value.existing_review)) return;
    reviewSubmitting.value = true; reviewFormError.value = '';
    const slug = product.value?.slug, existingId = eligibility.value.existing_review?.id;
    const sequence = reviewSequence;
    try {
        const payload = { rating: Number(reviewForm.value.rating), title: reviewForm.value.title.trim() || null, comment: reviewForm.value.comment.trim() };
        const response = existingId
            ? await api.put(`/products/${encodeURIComponent(slug)}/reviews/${existingId}`, payload)
            : await api.post(`/products/${encodeURIComponent(slug)}/reviews`, payload);
        if (sequence !== reviewSequence) return;
        const saved = response.data?.data;
        if (saved) eligibility.value = { ...eligibility.value, eligible: false, existing_review: saved };
        reviewFormError.value = '';
        toast.add({ severity: 'success', summary: saved?.status === 'published' ? 'Opinión publicada' : 'Opinión enviada a moderación', life: 3000 });
        await loadReviewEligibility();
    } catch {
        if (sequence === reviewSequence) reviewFormError.value = 'No se pudo enviar la opinión. Revisa los datos e inténtalo nuevamente.';
    } finally { if (sequence === reviewSequence) reviewSubmitting.value = false; }
}
function eligibilityMessage(reason) {
    return ({ module_closed: 'El módulo no acepta nuevas opiniones en este momento.', product_disabled: 'Este producto no acepta opiniones.', purchase_not_delivered: 'Necesitas una compra entregada de este producto para opinar.', already_reviewed: 'Ya enviaste una opinión para este producto.' })[reason] || 'No puedes enviar una opinión en este momento.';
}
function reviewStatusLabel(status) { return ({ pending: 'Pendiente de revisión', published: 'Publicada', hidden: 'Oculta', rejected: 'Rechazada' })[status] || 'En revisión'; }

const markImageFailed = url => { failedImages.value = new Set([...failedImages.value, url]); };
const addToCart = async event => {
    if (!product.value || remainingStock.value <= 0 || addingToCart.value) return;
    const amount = Math.min(qty.value, remainingStock.value);
    const trigger = event?.currentTarget || null;
    addingToCart.value = true;
    try {
        await nextTick();
        const added = cartStore.addItem(product.value, amount, trigger);
        if (!added) {
            toast.add({ severity: 'warn', summary: 'Stock limitado', detail: 'No se pudo agregar la cantidad solicitada. Revisa el stock disponible.', life: 3500 });
            return;
        }
        qty.value = 1;
    } catch {
        toast.add({ severity: 'error', summary: 'No se pudo agregar', detail: 'No se pudo actualizar el carrito. Inténtalo nuevamente.', life: 3500 });
    } finally {
        addingToCart.value = false;
    }
};

watch(() => route.params.slug, loadProduct, { immediate: true });
watch(remainingStock, value => { if (value > 0 && qty.value > value) qty.value = value; });
onBeforeUnmount(() => { controller?.abort(); relatedController?.abort(); resetProductReviews(); });
</script>
