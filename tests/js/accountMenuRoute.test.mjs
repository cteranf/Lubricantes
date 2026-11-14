import test from 'node:test';
import assert from 'node:assert/strict';
import { isAccountMenuRouteActive } from '../../resources/js/utils/accountMenuRoute.mjs';

const menuItems = [
    { label: 'Mi perfil', to: '/profile' },
    { label: 'Mis pedidos', to: '/orders', activeScope: 'subtree' },
    { label: 'Panel administrativo', to: '/admin/dashboard', activeScope: 'admin' },
    { label: 'Panel de tesorería', to: { name: 'treasury-payments' }, activeScope: 'treasury' },
    { label: 'Cerrar sesión', command: () => {} },
];

function activeLabels(path) {
    return menuItems.filter(item => isAccountMenuRouteActive(item, path)).map(item => item.label);
}

test('only profile is current on profile, including for an administrator', () => {
    const active = activeLabels('/profile');

    assert.deepEqual(active, ['Mi perfil']);
    assert.equal(active.length, 1);
});

test('orders is current on history and its customer child routes', () => {
    for (const path of ['/orders', '/orders/22/tracking', '/orders/payment-return']) {
        const active = activeLabels(path);

        assert.deepEqual(active, ['Mis pedidos']);
        assert.equal(active.length, 1);
    }
});

test('administrative shortcut is current only inside administrative routes', () => {
    assert.deepEqual(activeLabels('/admin/products'), ['Panel administrativo']);
    assert.deepEqual(activeLabels('/admin/dashboard'), ['Panel administrativo']);
    assert.deepEqual(activeLabels('/profile'), ['Mi perfil']);
});

test('treasury section is independent and logout is never active', () => {
    assert.deepEqual(activeLabels('/treasury/payments'), ['Panel de tesorería']);
    assert.equal(isAccountMenuRouteActive(menuItems[4], '/orders'), false);
    assert.equal(isAccountMenuRouteActive(menuItems[0], '/profile/edit'), false);
});
