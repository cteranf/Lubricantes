import test from 'node:test';
import assert from 'node:assert/strict';
import {
    NEWS_HOME_LIMIT,
    NEWS_PAGE_SIZE,
    newsLayoutVariant,
    newsResultSummary,
    newsRouteQuery,
    normalizeNewsPage,
    normalizeNewsQuery,
} from '../../resources/js/utils/newsPublic.mjs';

test('home layout selects no section, one featured card, two columns, or a three-column grid', () => {
    assert.equal(NEWS_HOME_LIMIT, 3);
    assert.equal(newsLayoutVariant(0), 'none');
    assert.equal(newsLayoutVariant(1), 'featured');
    assert.equal(newsLayoutVariant(2), 'two');
    assert.equal(newsLayoutVariant(3), 'grid');
    assert.equal(newsLayoutVariant(8), 'grid');
    assert.equal(newsLayoutVariant(-1), 'none');
});

test('search query normalization rejects arrays and invalid pages and bounds the supported search value', () => {
    assert.deepEqual(normalizeNewsQuery({ q: '  aceite  ', page: '2' }), { q: 'aceite', page: 2 });
    assert.deepEqual(normalizeNewsQuery({ q: ['aceite', 'filtro'], page: '-3' }), { q: '', page: 1 });
    assert.equal(normalizeNewsQuery({ q: 'x'.repeat(120) }).q.length, 100);
});

test('route query keeps only q and non-default page values', () => {
    assert.deepEqual(newsRouteQuery(' cuidado ', 1), { q: 'cuidado' });
    assert.deepEqual(newsRouteQuery('', 3), { page: '3' });
    assert.deepEqual(newsRouteQuery(' ', 0), {});
});

test('paginated API response is normalized without changing records or inventing metadata', () => {
    const record = { slug: 'aceite-5w30', title: 'Aceite 5W-30', image_url: null };
    assert.deepEqual(normalizeNewsPage({ data: [record], meta: { current_page: 2, last_page: 4, per_page: 9, total: 31 } }), {
        items: [record], currentPage: 2, lastPage: 4, perPage: 9, total: 31,
    });
    assert.deepEqual(normalizeNewsPage({ data: 'invalid' }), {
        items: [], currentPage: 1, lastPage: 1, perPage: NEWS_PAGE_SIZE, total: 0,
    });
});

test('result summary uses the server total for zero, singular, plural, and page ranges', () => {
    assert.equal(newsResultSummary({ total: 0, currentPage: 1, perPage: 9, itemCount: 0 }), 'No hay publicaciones para mostrar.');
    assert.equal(newsResultSummary({ total: 1, currentPage: 1, perPage: 9, itemCount: 1 }), 'Mostrando 1–1 de 1 guía');
    assert.equal(newsResultSummary({ total: 2, currentPage: 1, perPage: 9, itemCount: 2 }), 'Mostrando 1–2 de 2 guías');
    assert.equal(newsResultSummary({ total: 20, currentPage: 2, perPage: 9, itemCount: 9 }), 'Mostrando 10–18 de 20 guías');
});
