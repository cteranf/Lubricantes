<template>
    <article class="h-full">
        <RouterLink
            :to="{ name: 'GuideDetail', params: { slug: article.slug } }"
            class="group flex h-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition-shadow hover:shadow-lg focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-4 focus-visible:outline-blue-700 motion-reduce:transition-none"
            :class="featured ? 'flex-col md:flex-row' : 'flex-col'"
        >
            <div
                class="relative flex aspect-[16/9] shrink-0 items-center justify-center overflow-hidden bg-slate-100"
                :class="featured ? 'md:aspect-auto md:min-h-[18rem] md:w-2/5' : 'w-full'"
            >
                <img
                    v-if="hasImage"
                    :src="article.image_url"
                    alt=""
                    width="640"
                    height="360"
                    loading="lazy"
                    decoding="async"
                    class="h-full w-full object-cover motion-safe:transition-transform motion-safe:duration-300 motion-safe:group-hover:scale-[1.02]"
                    @error="imageFailed = true"
                >
                <span v-else class="flex h-full min-h-32 w-full items-center justify-center text-blue-700" aria-hidden="true">
                    <i class="pi pi-book text-4xl"></i>
                </span>
            </div>
            <div class="flex min-w-0 flex-1 flex-col p-5 sm:p-6" :class="featured ? 'justify-center md:p-8' : ''">
                <time v-if="article.published_at" :datetime="article.published_at" class="text-sm font-medium text-slate-500">
                    {{ formatCalendarDate(article.published_at) }}
                </time>
                <component :is="headingLevel" class="mt-2 text-xl font-bold leading-snug text-slate-950 group-hover:text-blue-800 sm:text-2xl">
                    {{ article.title }}
                </component>
                <p v-if="article.summary" class="mt-3 line-clamp-3 leading-relaxed text-slate-600">
                    {{ article.summary }}
                </p>
                <span class="mt-5 inline-flex items-center gap-2 font-bold text-blue-700">
                    Leer artículo <i class="pi pi-arrow-right" aria-hidden="true"></i>
                </span>
            </div>
        </RouterLink>
    </article>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { formatCalendarDate } from '@/utils/dateTime';

const props = defineProps({
    article: { type: Object, required: true },
    featured: { type: Boolean, default: false },
    headingLevel: { type: String, default: 'h2' },
});

const imageFailed = ref(false);
const hasImage = computed(() => typeof props.article.image_url === 'string' && props.article.image_url !== '' && !imageFailed.value);
watch(() => props.article.image_url, () => { imageFailed.value = false; });
</script>
