<?php

namespace Tests\Feature;

use App\Http\Resources\PaymentReceivingAccountResource;
use App\Models\PaymentReceivingAccount;
use App\Models\User;
use App\Services\TreasuryReceivingAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TreasuryReceivingAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_account_is_encrypted_masked_and_code_is_immutable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $account = app(TreasuryReceivingAccountService::class)->create(['code' => 'BCP-001', 'channel' => 'bank_transfer', 'display_name' => 'BCP', 'holder_name' => 'Titular', 'currency' => 'PEN', 'bank_name' => 'BCP', 'account_number' => '001-123', 'is_active' => true, 'is_default' => true], $admin);
        $this->assertStringNotContainsString('001123', (string) \DB::table('payment_receiving_accounts')->value('account_number'));
        $data = (new PaymentReceivingAccountResource($account))->resolve(request());
        $this->assertSame('••1123', $data['account_number_masked']);
        $this->assertArrayNotHasKey('account_number', $data);
        $this->expectException(InvalidArgumentException::class);
        $account->update(['code' => 'OTHER']);
    }

    public function test_channel_rules_defaults_and_server_snapshot_are_enforced(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(TreasuryReceivingAccountService::class);
        foreach ([['channel' => 'yape'], ['channel' => 'plin'], ['channel' => 'bank_transfer', 'bank_name' => 'BCP']] as $invalid) {
            try {
                $service->create($invalid + ['code' => uniqid('A'), 'display_name' => 'x', 'holder_name' => 'x', 'currency' => 'PEN'], $admin);
                $this->fail('Regla de canal omitida.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        } $yape = PaymentReceivingAccount::create(['code' => 'YAPE-1', 'channel' => 'yape', 'display_name' => 'Yape', 'holder_name' => 'Titular', 'currency' => 'PEN', 'phone' => '999 123 456', 'qr_path' => 'q.png', 'qr_disk' => 'treasury_qr', 'is_active' => true, 'is_default' => true]);
        $plin = PaymentReceivingAccount::create(['code' => 'PLIN-1', 'channel' => 'plin', 'display_name' => 'Plin', 'holder_name' => 'Titular', 'currency' => 'PEN', 'phone' => '988123456', 'qr_path' => 'p.png', 'qr_disk' => 'treasury_qr', 'is_active' => true, 'is_default' => true]);
        $this->assertTrue($yape->is_default);
        $this->assertTrue($plin->is_default);
        $this->assertSame(['receiving_account_id' => $yape->id, 'code' => 'YAPE-1', 'channel' => 'yape', 'display_name' => 'Yape', 'holder_name' => 'Titular', 'currency' => 'PEN', 'bank_name' => null, 'snapshot_version' => 1], $service->snapshot($yape, 'yape'));
        $this->assertSame('receiving-account:YAPE-1', $service->fingerprintIdentifier($yape));
    }

    public function test_admin_routes_are_exclusive_and_qr_replacement_uses_private_disk(): void
    {
        Storage::fake('treasury_qr');
        $account = PaymentReceivingAccount::create(['code' => 'BANK-1', 'channel' => 'bank_transfer', 'display_name' => 'Banco', 'holder_name' => 'Titular', 'currency' => 'PEN', 'bank_name' => 'Banco', 'account_number' => '123', 'is_active' => true]);
        $this->getJson('/api/v1/admin/treasury/receiving-accounts')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->getJson('/api/v1/admin/treasury/receiving-accounts')->assertForbidden();
        Sanctum::actingAs($admin = User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/admin/treasury/receiving-accounts')->assertOk()->assertJsonMissingPath('data.0.account_number');
        $file = UploadedFile::fake()->image('evil-name.png');
        $this->post('/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr', ['qr' => $file])->assertOk();
        $account->refresh();
        Storage::disk('treasury_qr')->assertExists($account->qr_path);
        $this->assertStringNotContainsString('evil-name.png', $account->qr_path);
    }

    public function test_incomplete_accounts_can_only_exist_inactive_and_activation_enforces_operational_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(TreasuryReceivingAccountService::class);
        $yape = $service->create(['code' => 'YAPE-STAGE', 'channel' => 'yape', 'display_name' => 'Yape', 'holder_name' => 'Titular', 'currency' => 'PEN', 'is_active' => false], $admin);
        $bank = $service->create(['code' => 'BANK-STAGE', 'channel' => 'bank_transfer', 'display_name' => 'Banco', 'holder_name' => 'Titular', 'currency' => 'PEN', 'is_active' => false], $admin);
        foreach ([$yape, $bank] as $account) {
            try {
                $service->changeStatus($account, true, $admin);
                $this->fail('Cuenta incompleta activada.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFalse($service->changeStatus($yape, false, $admin)->is_default);
    }

    public function test_normalizes_peruvian_phone_cci_and_synchronizes_active_default_channel(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $account = app(TreasuryReceivingAccountService::class)->create(['code' => 'YAPE-DEFAULT', 'channel' => 'yape', 'display_name' => 'Yape', 'holder_name' => 'Titular', 'currency' => 'PEN', 'phone' => '+51 999-123-456', 'qr_path' => 'receiving-accounts/q.png', 'qr_disk' => 'treasury_qr', 'is_active' => true, 'is_default' => true], $admin);
        $this->assertSame('999123456', $account->phone);
        $this->assertSame('yape', $account->active_default_channel);
        $account = app(TreasuryReceivingAccountService::class)->changeStatus($account, false, $admin);
        $this->assertNull($account->active_default_channel);
        $this->assertFalse($account->is_default);
        try {
            app(TreasuryReceivingAccountService::class)->create(['code' => 'BAD/CODE', 'channel' => 'bank_transfer', 'display_name' => 'Banco', 'holder_name' => 'Titular', 'currency' => 'PEN', 'is_active' => false], $admin);
            $this->fail('Código hostil aceptado.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_png_jpeg_and_webp_qr_uploads_are_private_and_generated_by_server(): void
    {
        Storage::fake('treasury_qr');
        Sanctum::actingAs($admin = User::factory()->create(['role' => 'admin']));
        foreach (['png', 'jpeg', 'webp'] as $extension) {
            $account = $this->qrAccount('QR-'.$extension);
            $response = $this->post('/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr', ['qr' => UploadedFile::fake()->image('hostile.'.$extension)]);
            $response->assertOk()->assertJsonMissingPath('data.qr_path')->assertJsonMissingPath('data.qr_disk')->assertJsonPath('data.has_qr', true);
            $account->refresh();
            Storage::disk('treasury_qr')->assertExists($account->qr_path);
            $this->assertStringNotContainsString('hostile.', $account->qr_path);
            $this->assertStringNotContainsString('..', $account->qr_path);
        }
    }

    public function test_invalid_or_missing_qr_preserves_existing_file_and_returns_422(): void
    {
        Storage::fake('treasury_qr');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $account = $this->qrAccount('QR-INVALID');
        Storage::disk('treasury_qr')->put('receiving-accounts/original.png', 'old');
        $account->update(['qr_path' => 'receiving-accounts/original.png', 'qr_disk' => 'treasury_qr']);
        $this->postJson('/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr', [])->assertUnprocessable();
        $this->withHeader('Accept', 'application/json')->post('/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr', ['qr' => UploadedFile::fake()->create('bad.pdf', 4, 'application/pdf')])->assertUnprocessable();
        Storage::disk('treasury_qr')->assertExists('receiving-accounts/original.png');
        $this->assertSame('receiving-accounts/original.png', $account->fresh()->qr_path);
    }

    public function test_qr_preview_is_private_nosniff_and_missing_files_are_404(): void
    {
        Storage::fake('treasury_qr');
        $account = $this->qrAccount('QR-PREVIEW');
        Storage::disk('treasury_qr')->put('receiving-accounts/preview.png', 'png-bytes');
        $account->update(['qr_path' => 'receiving-accounts/preview.png', 'qr_disk' => 'treasury_qr']);
        $this->getJson('/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->get('/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        Storage::disk('treasury_qr')->delete('receiving-accounts/preview.png');
        $this->get('/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr')->assertNotFound();
    }

    public function test_qr_validation_rejects_disguised_non_images_and_oversized_uploads_without_writing(): void
    {
        Storage::fake('treasury_qr');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $account = $this->qrAccount('QR-MATRIX');
        Storage::disk('treasury_qr')->put('receiving-accounts/original.png', 'old');
        $account->update(['qr_path' => 'receiving-accounts/original.png', 'qr_disk' => 'treasury_qr']);
        $invalid = [
            UploadedFile::fake()->createWithContent('vector.svg', '<svg/>'), UploadedFile::fake()->createWithContent('vector.png', '<svg/>'),
            UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'), UploadedFile::fake()->createWithContent('shell.png', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('text.jpg', 'not-an-image'), UploadedFile::fake()->createWithContent('broken.png', "\x89PNG\r\n"),
            UploadedFile::fake()->createWithContent('empty.png', ''), UploadedFile::fake()->create('large.png', config('treasury.qr_max_kilobytes') + 1, 'image/png'),
        ];
        foreach ($invalid as $file) {
            $this->withHeader('Accept', 'application/json')->post('/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr', ['qr' => $file])->assertUnprocessable()->assertJsonMissingPath('qr_path')->assertJsonMissingPath('trace');
            $this->assertSame('receiving-accounts/original.png', $account->fresh()->qr_path);
            Storage::disk('treasury_qr')->assertExists('receiving-accounts/original.png');
        }
    }

    public function test_admin_routes_reject_non_admins_and_missing_accounts(): void
    {
        Storage::fake('treasury_qr');
        $account = $this->qrAccount('AUTH-MATRIX');
        $routes = [
            ['getJson', '/api/v1/admin/treasury/receiving-accounts', []],
            ['getJson', '/api/v1/admin/treasury/receiving-accounts/'.$account->id, []],
            ['putJson', '/api/v1/admin/treasury/receiving-accounts/'.$account->id, []],
            ['patchJson', '/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/status', []],
            ['postJson', '/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr', []],
            ['getJson', '/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr', []],
        ];
        foreach ($routes as [$method, $uri, $payload]) {
            $response = $this->{$method}($uri, $payload);
            $response->assertUnauthorized();
        }
        foreach (['customer', 'treasury'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->getJson('/api/v1/admin/treasury/receiving-accounts')->assertForbidden();
            $this->getJson('/api/v1/admin/treasury/receiving-accounts/'.$account->id.'/qr')->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]));
        $this->getJson('/api/v1/admin/treasury/receiving-accounts')->assertForbidden()->assertJsonPath('code', 'account_inactive');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['GET /api/v1/admin/treasury/receiving-accounts/999999', 'PUT /api/v1/admin/treasury/receiving-accounts/999999', 'PATCH /api/v1/admin/treasury/receiving-accounts/999999/status', 'POST /api/v1/admin/treasury/receiving-accounts/999999/qr'] as $spec) {
            [$method, $uri] = explode(' ', $spec, 2);
            $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertNotFound();
        }
    }

    public function test_validated_endpoints_ignore_protected_mass_assignment_and_paginate_safe_data(): void
    {
        Sanctum::actingAs($admin = User::factory()->create(['role' => 'admin']));
        $payload = ['code' => 'MASS-SAFE', 'channel' => 'bank_transfer', 'display_name' => 'Banco', 'holder_name' => 'Titular', 'currency' => 'PEN', 'bank_name' => 'Banco', 'account_number' => '123456', 'is_active' => true, 'id' => 999, 'qr_path' => '../evil', 'qr_disk' => 'public', 'active_default_channel' => 'plin', 'created_by' => 999, 'updated_by' => 999, 'receiving_account_snapshot' => ['secret' => 'x'], 'duplicate_fingerprint' => 'x'];
        $this->postJson('/api/v1/admin/treasury/receiving-accounts', $payload)->assertCreated()->assertJsonMissingPath('data.qr_path');
        $account = PaymentReceivingAccount::whereCode('MASS-SAFE')->firstOrFail();
        $this->assertSame($admin->id, $account->created_by);
        $this->assertSame($admin->id, $account->updated_by);
        $this->assertNull($account->qr_path);
        $this->assertNull($account->active_default_channel);
        $this->getJson('/api/v1/admin/treasury/receiving-accounts?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/admin/treasury/receiving-accounts?channel=bank_transfer&is_active=1')->assertOk()->assertJsonMissingPath('data.0.account_number')->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);
    }

    public function test_admin_can_set_only_an_operational_account_as_default_per_channel(): void
    {
        Sanctum::actingAs($admin = User::factory()->create(['role' => 'admin']));
        $first = $this->qrAccount('DEFAULT-ONE');
        $second = $this->qrAccount('DEFAULT-TWO');
        $this->patchJson('/api/v1/admin/treasury/receiving-accounts/'.$first->id.'/default', ['is_default' => true])->assertOk();
        $this->patchJson('/api/v1/admin/treasury/receiving-accounts/'.$second->id.'/default', ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame('bank_transfer', $second->fresh()->active_default_channel);
        $this->assertSame($admin->id, $second->fresh()->updated_by);
        $second->update(['is_active' => false]);
        $this->patchJson('/api/v1/admin/treasury/receiving-accounts/'.$second->id.'/default', ['is_default' => true])->assertUnprocessable();
    }

    private function qrAccount(string $code): PaymentReceivingAccount
    {
        return PaymentReceivingAccount::create(['code' => $code, 'channel' => 'bank_transfer', 'display_name' => 'Banco', 'holder_name' => 'Titular', 'currency' => 'PEN', 'bank_name' => 'Banco', 'account_number' => '123456', 'is_active' => true]);
    }
}
