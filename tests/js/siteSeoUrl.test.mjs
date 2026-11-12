import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolveSiteCanonicalUrl, resolveSiteImageUrl } from '../../resources/js/utils/siteSeoUrl.mjs';

test('canonical URLs preserve the configured base path with either slash form', () => {
    for (const base of ['https://example.org', 'https://example.org/']) {
        assert.equal(resolveSiteCanonicalUrl('/catalogo', base), 'https://example.org/catalogo');
    }

    for (const base of ['https://example.org/tienda', 'https://example.org/tienda/']) {
        assert.equal(resolveSiteCanonicalUrl('/catalogo', base), 'https://example.org/tienda/catalogo');
        assert.equal(resolveSiteCanonicalUrl('/tienda/catalogo', base), 'https://example.org/tienda/catalogo');
        assert.equal(resolveSiteCanonicalUrl('/product/slug', base), 'https://example.org/tienda/product/slug');
    }
});

test('canonical URLs preserve validated absolute URLs and discard unapproved query and fragments', () => {
    assert.equal(
        resolveSiteCanonicalUrl('https://other.example.org/catalogo?token=synthetic#section', 'https://example.org/tienda'),
        'https://other.example.org/catalogo',
    );
    assert.equal(
        resolveSiteCanonicalUrl('/catalogo?token=synthetic#section', 'https://example.org/tienda'),
        'https://example.org/tienda/catalogo',
    );
    assert.equal(
        resolveSiteCanonicalUrl('/catalog?category=filtros', 'https://example.org/tienda'),
        'https://example.org/tienda/catalog?category=filtros',
    );
    assert.equal(resolveSiteCanonicalUrl('https://user:secret@example.org/path', 'https://example.org'), null);
});

test('local and absolute image paths reject traversal and unsafe encoding at every decode stage', () => {
    const adversarialPaths = [
        '../imagen.png',
        '%2e%2e/imagen.png',
        '%252e%252e/imagen.png',
        '%25252e%25252e/imagen.png',
        '%2e%2e%2fimagen.png',
        '%252e%252e%252fimagen.png',
        '%5c',
        '%255c',
        '%00',
        '%2500',
        '%0d%0a',
        '%250d%250a',
        '%2-',
        '//dominio.example.org/imagen.png',
        'javascript:alert(1)',
        'https://cdn.example.org/%252e%252e%252fprivate.png',
    ];

    for (const value of adversarialPaths) {
        assert.equal(resolveSiteImageUrl(value, 'https://example.org/tienda', true), null, `unsafe image path rejected`);
    }
});

test('properly encoded spaces, UTF-8, local assets and HTTPS images remain supported under base path', () => {
    const base = 'https://example.org/tienda/';
    assert.equal(resolveSiteImageUrl('/images/mi%20imagen.png', base), 'https://example.org/tienda/images/mi%20imagen.png');
    assert.equal(resolveSiteImageUrl('/images/caf%C3%A9.png', base), 'https://example.org/tienda/images/caf%C3%A9.png');
    assert.equal(resolveSiteImageUrl('https://cdn.example.org/images/caf%C3%A9.png', base, true), 'https://cdn.example.org/images/caf%C3%A9.png');
    assert.equal(resolveSiteImageUrl('https://cdn.example.org/images/caf%C3%A9.png', base), null);
});

test('manifest-relative URLs resolve from root and subpath installations to existing icons', () => {
    const manifestFile = new URL('../../public/site.webmanifest', import.meta.url);
    const manifest = JSON.parse(readFileSync(manifestFile, 'utf8'));

    assert.equal(manifest.start_url, './');
    assert.equal(manifest.scope, './');
    assert.ok(manifest.icons.length > 0);
    for (const icon of manifest.icons) {
        assert.ok(icon.src.startsWith('./'));
        assert.ok(!icon.src.startsWith('/'));
        const iconFile = new URL(icon.src, manifestFile);
        assert.ok(existsSync(fileURLToPath(iconFile)));

        const rootManifestUrl = new URL('https://example.test/site.webmanifest');
        const subpathManifestUrl = new URL('https://example.test/tienda/site.webmanifest');
        assert.equal(new URL(manifest.start_url, rootManifestUrl).pathname, '/');
        assert.equal(new URL(manifest.scope, rootManifestUrl).pathname, '/');
        assert.equal(new URL(icon.src, rootManifestUrl).pathname, `/android-chrome-${icon.sizes.split('x')[0]}x${icon.sizes.split('x')[0]}.png`);
        assert.equal(new URL(manifest.start_url, subpathManifestUrl).pathname, '/tienda/');
        assert.equal(new URL(manifest.scope, subpathManifestUrl).pathname, '/tienda/');
        assert.equal(new URL(icon.src, subpathManifestUrl).pathname, `/tienda/android-chrome-${icon.sizes.split('x')[0]}x${icon.sizes.split('x')[0]}.png`);
    }
});
