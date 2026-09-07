<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementCycleTimesReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_cycle_times_empty_contract_is_stable(): void
    {
        $r = $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/v1/admin/reports/management/cycle_times')->assertOk()->json();
        $this->assertArrayHasKey('available_metrics', $r);
        $this->assertArrayHasKey('unavailable_metrics', $r);
        $this->assertIsInt($r['total_samples']);
    }
}
