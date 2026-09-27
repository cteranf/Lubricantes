<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminUsersFrontendTest extends TestCase
{
    public function test_users_screen_and_admin_navigation_use_professional_safe_static_contracts(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Users.vue'));
        $layout = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        $router = file_get_contents(resource_path('js/router.js'));
        $this->assertStringContainsString("'/admin/users'", $view);
        $this->assertStringContainsString("'/admin/users'", $router);
        $this->assertStringContainsString("label: 'Usuarios'", $layout);
        $this->assertStringContainsString('SEGURIDAD Y ACCESO', $view);
        $this->assertStringContainsString('Administración de usuarios', $view);
        $this->assertStringContainsString('pi pi-user-plus', $view);
        $this->assertStringContainsString('pi pi-search', $view);
        $this->assertStringContainsString('Aplicar', $view);
        $this->assertStringContainsString('Limpiar', $view);
        $this->assertStringContainsString('Actualizar usuarios', $view);
        $this->assertStringContainsString('Usuario</th>', $view);
        $this->assertStringContainsString('Correo</th>', $view);
        $this->assertStringContainsString('roleTone', $view);
        $this->assertStringContainsString('roleIcon', $view);
        $this->assertStringContainsString('<option value="treasury">Tesorería</option>', $view);
        $this->assertStringContainsString("role === 'treasury' ? 'Tesorería'", $view);
        $this->assertStringContainsString("role === 'treasury' ? 'badge-treasury'", $view);
        $this->assertStringContainsString("role === 'treasury' ? 'pi pi-wallet'", $view);
        $this->assertStringContainsString("form.role !== 'treasury'", $view);
        $this->assertStringContainsString("if (form.role === 'treasury') form.can_deliver = false", $view);
        $this->assertStringContainsString('watch(() => form.role', $view);
        $this->assertStringContainsString("api.post('/admin/users', form)", $view);
        $this->assertStringContainsString('api.put(`/admin/users/${selected.value.id}`, form)', $view);
        $this->assertStringContainsString('statusLabel', $view);
        $this->assertStringContainsString('initials', $view);
        $this->assertStringContainsString('pi pi-pencil', $view);
        $this->assertStringContainsString('pi pi-key', $view);
        $this->assertStringContainsString('data-tooltip="Editar usuario"', $view);
        $this->assertStringContainsString('aria-label="Restablecer contraseña"', $view);
        $this->assertStringContainsString('autocomplete="new-password"', $view);
        $this->assertStringContainsString('overflow-x-auto', $view);
        $this->assertStringContainsString('useConfirm', $view);
        $this->assertStringContainsString('confirm.require', $view);
        $this->assertStringContainsString('Mostrando ${from}–${to} de ${total} usuarios', $view);
        $this->assertStringContainsString('animate-pulse', $view);
        $this->assertStringContainsString('closeModal', $view);
        $this->assertStringContainsString('Object.assign(form, blank())', $view);
        $this->assertStringNotContainsString('window.confirm', $view);
        $this->assertStringNotContainsString('text-blue-700 underline', $view);
        $this->assertStringNotContainsString('v-html', $view);
        $this->assertStringNotContainsString('localStorage.setItem(\'password\'', $view);
    }
}
