<?php

namespace Tests\Feature;

use Tests\TestCase;

class BusinessProfileFrontendTest extends TestCase
{
    public function test_admin_business_profile_screen_has_progressive_grouped_form_and_logo_lifecycle(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/BusinessProfile.vue'));
        $router = file_get_contents(resource_path('js/router.js'));
        $layout = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        $request = file_get_contents(app_path('Http/Requests/Admin/UpdateBusinessProfileRequest.php'));
        $fieldInputStart = strpos($view, 'const FieldInput = defineComponent({');
        $fieldInputEnd = strpos($view, 'const loading = ref(true);', $fieldInputStart);
        $fieldInput = substr($view, $fieldInputStart, $fieldInputEnd - $fieldInputStart);

        $this->assertStringContainsString('Identidad del negocio', $view);
        $this->assertStringContainsString('Dirección y contacto', $view);
        $this->assertStringContainsString('Configuración operativa de consulta', $view);
        $this->assertStringContainsString('Información pendiente', $view);
        $this->assertStringContainsString('RUC', $view);
        $this->assertStringContainsString('no consulta SUNAT', $view);
        $this->assertStringContainsString('config/payment.php', $view);
        $this->assertStringContainsString('config/app.php', $view);
        $this->assertStringContainsString('URL.createObjectURL', $view);
        $this->assertStringContainsString('URL.revokeObjectURL', $view);
        $this->assertStringContainsString('logoLimits.max_width', $view);
        $this->assertStringContainsString('logoLimits.max_height', $view);
        $this->assertStringContainsString('Retirar vista previa nueva', $view);
        $this->assertStringContainsString('Eliminar logo guardado', $view);
        $this->assertSame(1, substr_count($view, 'label="Guardar'));
        $this->assertStringContainsString("import { useToast } from 'primevue/usetoast';", $view);
        $this->assertStringContainsString("toast.add({ severity: 'success', summary: 'Datos del negocio guardados'", $view);
        $this->assertStringContainsString("api.post('/admin/business-profile', body)", $view);
        $this->assertStringContainsString("formError.value = errors.value.request?.[0] ?? '';", $view);
        $this->assertStringContainsString('role="alert"', $view);
        $this->assertStringContainsString('inheritAttrs: false', $fieldInput);
        $this->assertStringContainsString("class: ['form-control', attrs.class]", $fieldInput);
        $this->assertStringContainsString(':deep(.form-control)', $view);
        $this->assertStringContainsString("path: '/admin/business-profile'", $router);
        $this->assertStringContainsString("label: 'Datos del negocio'", $layout);
        $this->assertStringContainsString('$this->user()?->is_active', $request);
        $this->assertStringContainsString('$this->user()?->isAdmin()', $request);
    }
}
