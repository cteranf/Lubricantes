<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementOperationsCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_csv_has_controlled_headers(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/api/v1/admin/reports/management/operations/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3));
        $this->assertStringContainsString('Pedido', $content);
        $this->assertStringContainsString('Ruta administrativa', $content);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json'])->get('/api/v1/admin/reports/management/operations/export')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get('/api/v1/admin/reports/management/operations/export')->assertForbidden();
    }
}
