import { onBeforeUnmount, shallowRef, unref, watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import { resolveSiteCanonicalUrl, resolveSiteImageUrl } from '@/utils/siteSeoUrl.mjs';

const pageSeo = shallowRef(null);
let configCache;

const cleanText = (value, limit = 300) => String(value ?? '')
    .replace(/&nbsp;|&#160;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;|&apos;/gi, "'")
    .replace(/<\s*(script|style|template|noscript)\b(?:[^>"']|"[^"]*"|'[^']*')*>(.*?)(?:<\s*\/\s*\1\s*>|$)/gis, ' ')
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, limit);

function readConfig() {
    if (configCache) return configCache;
    try {
        configCache = JSON.parse(document.getElementById('site-seo-config')?.textContent || '{}');
    } catch {
        configCache = {};
    }
    const siteName = cleanText(configCache.name || 'LubriStore', 100);
    const publicUrlValid = configCache.publicUrlValid === true && /^https?:\/\//i.test(configCache.baseUrl || '');
    const baseUrl = publicUrlValid ? String(configCache.baseUrl).replace(/\/+$/, '') : window.location.origin;
    const socialImage = safeImage(configCache.socialImage, baseUrl, true)
        || safeImage('/images/lubristore-social.png', baseUrl);
    return (configCache = {
        ...configCache,
        name: siteName,
        baseUrl,
        description: cleanText(configCache.description || 'Compra productos automotrices en LubriStore.', 300),
        socialImage,
        publicUrlValid,
        language: configCache.language || 'es',
        locale: configCache.locale || 'es_PE',
        country: configCache.country || 'PE',
        currency: configCache.currency || 'PEN',
        organization: configCache.organization || { name: siteName, url: baseUrl, logo: `${baseUrl}/android-chrome-512x512.png`, sameAs: [] },
    });
}

export function getSiteSeoConfig() {
    const config = readConfig();

    return Object.freeze({
        name: config.name,
        description: config.description,
        baseUrl: config.baseUrl,
        socialImage: config.socialImage,
        language: config.language,
        locale: config.locale,
        country: config.country,
        currency: config.currency,
        twitterHandle: config.twitterHandle || null,
        publicUrlValid: config.publicUrlValid,
    });
}

function safeImage(value, baseUrl, allowExternal = false) {
    return resolveSiteImageUrl(value, baseUrl, allowExternal);
}

function absoluteUrl(path, baseUrl) {
    return resolveSiteCanonicalUrl(path || '/', baseUrl);
}

function safeOrganizationUrl(value, baseUrl) {
    if (typeof value !== 'string' || !value.trim() || /[\\\u0000-\u0020\u007f]/.test(value)) return null;
    try {
        const url = new URL(value);
        if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) return null;
        return url.href;
    } catch {
        return null;
    }
}

function setMeta(attribute, key, value) {
    const selector = `meta[${attribute}="${key}"]`;
    let element = document.head.querySelector(selector);
    if (value === null || value === undefined || value === '') {
        element?.remove();
        return;
    }
    if (!element) {
        element = document.createElement('meta');
        element.setAttribute(attribute, key);
        document.head.append(element);
    }
    element.setAttribute('content', String(value));
}

function moneyMinor(value) {
    if (typeof value !== 'string' && typeof value !== 'number') return null;
    const text = String(value);
    if (!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(text)) return null;
    const [units, fraction = ''] = text.split('.');
    try {
        return BigInt(units) * 100n + BigInt(fraction.padEnd(2, '0'));
    } catch {
        return null;
    }
}

function minorString(value) {
    const whole = value / 100n;
    const fraction = String(value % 100n).padStart(2, '0');
    return `${whole}.${fraction}`;
}

function buildJsonLd(meta, config, canonical, image) {
    const organization = config.organization;
    const schemas = [{
        '@context': 'https://schema.org',
        '@type': 'Organization',
        name: cleanText(organization.name || config.name, 100),
        url: safeOrganizationUrl(organization.url, config.baseUrl) || config.baseUrl,
        logo: safeImage(organization.logo, config.baseUrl) || safeImage('/android-chrome-512x512.png', config.baseUrl),
        ...(Array.isArray(organization.sameAs) && organization.sameAs.length ? { sameAs: organization.sameAs.filter(item => typeof item === 'string' && /^https:\/\//i.test(item)) } : {}),
    }, {
        '@context': 'https://schema.org',
        '@type': 'WebSite',
        name: config.name,
        url: config.baseUrl,
        inLanguage: config.locale.replace('_', '-'),
        potentialAction: {
            '@type': 'SearchAction',
            target: { '@type': 'EntryPoint', urlTemplate: `${config.baseUrl}/catalog?search={search_term_string}` },
            'query-input': 'required name=search_term_string',
        },
    }];

    if (Array.isArray(meta.breadcrumbs) && meta.breadcrumbs.length) {
        schemas.push({
            '@context': 'https://schema.org',
            '@type': 'BreadcrumbList',
            itemListElement: meta.breadcrumbs.map((item, index) => ({
                '@type': 'ListItem', position: index + 1, name: cleanText(item.name, 160), item: absoluteUrl(item.path, config.baseUrl),
            })),
        });
    }

    if (meta.product && typeof meta.product === 'object') {
        const product = meta.product;
        const schema = {
            '@context': 'https://schema.org',
            '@type': 'Product',
            name: cleanText(product.name, 200),
            description: cleanText(product.description, 300),
            url: canonical,
        };
        if (typeof product.sku === 'string' && product.sku.trim()) schema.sku = cleanText(product.sku, 80);
        if (typeof product.brand === 'string' && product.brand.trim()) schema.brand = { '@type': 'Brand', name: cleanText(product.brand, 100) };
        if (Array.isArray(product.images)) {
            const images = product.images.map(value => safeImage(value, config.baseUrl)).filter(Boolean);
            if (images.length) schema.image = images;
        } else if (image) {
            schema.image = [image];
        }

        const regular = moneyMinor(product.price);
        const sale = moneyMinor(product.sale_price);
        const hasStock = typeof product.stock === 'number' || (typeof product.stock === 'string' && /^\d+$/.test(product.stock));
        const stock = hasStock ? Number(product.stock) : NaN;
        const price = regular !== null && sale !== null && sale > 0n && sale < regular ? sale : regular;
        if (price !== null && hasStock && Number.isSafeInteger(stock) && stock >= 0) {
            schema.offers = {
                '@type': 'Offer',
                price: minorString(price),
                priceCurrency: config.currency,
                availability: stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                url: canonical,
            };
        }
        schemas.push(schema);
    }

    return schemas;
}

function fallbackForRoute(route, config) {
    const allowed = route.path === '/' || route.path === '/catalog' || route.path === '/about' || route.path === '/contact';
    const protectedRoute = route.matched.some(record => record.meta?.requiresAuth || record.meta?.requiresAdmin || record.meta?.requiresTreasury || record.meta?.noindex);
    return {
        title: config.name,
        description: config.description,
        robots: config.publicUrlValid && allowed && !protectedRoute ? 'index,follow' : 'noindex,nofollow',
        canonicalPath: route.path === '/' ? '/' : allowed && !protectedRoute ? route.path : null,
        type: 'website',
        image: config.socialImage,
    };
}

function applySeo(meta, config) {
    const title = cleanText(meta.title || config.name, 180);
    const description = cleanText(meta.description || config.description, 300);

    if (!config.publicUrlValid) {
        document.title = title;
        document.documentElement.lang = `${config.language}-${String(config.country).toUpperCase()}`;
        setMeta('name', 'description', description);
        setMeta('name', 'robots', 'noindex,nofollow');
        setMeta('name', 'theme-color', '#1d4ed8');
        setMeta('property', 'og:site_name', config.name);
        setMeta('property', 'og:type', 'website');
        setMeta('property', 'og:title', title);
        setMeta('property', 'og:description', description);
        setMeta('property', 'og:url', null);
        setMeta('property', 'og:image', null);
        setMeta('property', 'og:image:alt', null);
        setMeta('property', 'og:locale', config.locale);
        setMeta('name', 'twitter:card', 'summary');
        setMeta('name', 'twitter:title', title);
        setMeta('name', 'twitter:description', description);
        setMeta('name', 'twitter:image', null);
        setMeta('name', 'twitter:site', config.twitterHandle || null);
        document.getElementById('seo-canonical')?.remove();
        const jsonLdElement = document.getElementById('site-seo-jsonld');
        if (jsonLdElement) jsonLdElement.textContent = '[]';
        return;
    }

    const canonical = meta.canonicalPath ? absoluteUrl(meta.canonicalPath, config.baseUrl) : null;
    const image = safeImage(meta.image, config.baseUrl) || config.socialImage;
    const robots = meta.robots || 'noindex,nofollow';
    const type = meta.type === 'product' ? 'product' : 'website';

    document.title = title;
    document.documentElement.lang = `${config.language}-${String(config.country).toUpperCase()}`;
    setMeta('name', 'description', description);
    setMeta('name', 'robots', robots);
    setMeta('name', 'theme-color', '#1d4ed8');
    setMeta('property', 'og:site_name', config.name);
    setMeta('property', 'og:type', type);
    setMeta('property', 'og:title', title);
    setMeta('property', 'og:description', description);
    setMeta('property', 'og:url', canonical || config.baseUrl);
    setMeta('property', 'og:image', image);
    setMeta('property', 'og:image:alt', cleanText(meta.imageAlt || title, 200));
    setMeta('property', 'og:locale', config.locale);
    setMeta('name', 'twitter:card', 'summary_large_image');
    setMeta('name', 'twitter:title', title);
    setMeta('name', 'twitter:description', description);
    setMeta('name', 'twitter:image', image);
    setMeta('name', 'twitter:site', config.twitterHandle || null);

    let canonicalElement = document.getElementById('seo-canonical');
    if (!canonical) {
        canonicalElement?.remove();
    } else {
        if (!canonicalElement) {
            canonicalElement = document.createElement('link');
            canonicalElement.id = 'seo-canonical';
            canonicalElement.rel = 'canonical';
            document.head.append(canonicalElement);
        }
        canonicalElement.href = canonical;
    }

    const jsonLdElement = document.getElementById('site-seo-jsonld');
    if (jsonLdElement) jsonLdElement.textContent = JSON.stringify(buildJsonLd(meta, config, canonical || config.baseUrl, image));
}

export function useSiteSeo(source) {
    const route = useRoute();
    const owner = Symbol('site-seo');
    const stop = watchEffect(() => {
        pageSeo.value = {
            owner,
            routeKey: route.fullPath,
            metadata: typeof source === 'function' ? source() : unref(source),
        };
    });

    onBeforeUnmount(() => {
        stop();
        if (pageSeo.value?.owner === owner) pageSeo.value = null;
    });
}

export function useSiteSeoHead() {
    const route = useRoute();
    const config = readConfig();
    watchEffect(() => {
        const current = pageSeo.value?.routeKey === route.fullPath ? pageSeo.value.metadata : null;
        const metadata = current || fallbackForRoute(route, config);
        applySeo(metadata, config);
    });
}
