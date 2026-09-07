<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementCycleTimesCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_cycle_times_csv_has_headers(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/api/v1/admin/reports/management/cycle_times/export');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3));
        $this->assertStringContainsString('Métrica', $content);
        $this->assertStringNotContainsString('NaN', $content);
        $this->assertStringNotContainsString('Infinity', $content);
    }
}
