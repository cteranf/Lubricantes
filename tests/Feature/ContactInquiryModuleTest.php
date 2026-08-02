<?php

namespace Tests\Feature;

use App\Mail\NewContactInquiryMail;
use App\Models\ContactInquiry;
use App\Models\ContactInquiryHistory;
use App\Models\User;
use App\Services\ContactFormSecurity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactInquiryModuleTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * SQLite in-memory databases disappear with the application, so an
     * explicit rollback is unnecessary. Avoiding it also keeps legacy down()
     * methods that drop foreign keys from masking the assertions in this file.
     */
    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['contact.minimum_fill_seconds' => 0, 'contact.notification_email' => null]);
    }

    public function test_guest_can_submit_valid_inquiry_and_phone_is_optional(): void
    {
        $response = $this->postJson('/api/v1/contact-inquiries', $this->payload(['phone' => null]));
        $response->assertCreated()->assertJsonStructure(['message', 'reference']);
        $this->assertDatabaseHas('contact_inquiries', ['email' => 'visitor@example.com', 'phone' => null, 'status' => ContactInquiry::PENDING]);
        $this->assertDatabaseHas('contact_inquiry_histories', ['event_type' => 'created', 'to_status' => ContactInquiry::PENDING]);
    }

    public function test_authenticated_user_is_linked_to_inquiry(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/contact-inquiries', $this->payload())->assertCreated();
        $this->assertDatabaseHas('contact_inquiries', ['user_id' => $user->id]);
    }

    public function test_required_fields_limits_and_formats_are_validated(): void
    {
        $this->postJson('/api/v1/contact-inquiries', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'subject', 'message', 'submission_token', 'form_started_at', 'form_signature']);
        $payload = $this->payload(['name' => 'A', 'email' => 'invalid', 'phone' => 'javascript:alert(1)', 'subject' => 'Hi', 'message' => 'short']);
        $this->postJson('/api/v1/contact-inquiries', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'subject', 'message']);
    }

    public function test_honeypot_and_invalid_form_signature_do_not_persist(): void
    {
        $this->postJson('/api/v1/contact-inquiries', $this->payload(['website' => 'spam']))->assertUnprocessable();
        $this->postJson('/api/v1/contact-inquiries', $this->payload(['form_signature' => str_repeat('0', 64)]))->assertUnprocessable()->assertJsonValidationErrors('form_token');
        $this->assertDatabaseCount('contact_inquiries', 0);
    }

    public function test_specific_rate_limit_blocks_excessive_submissions(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->postJson('/api/v1/contact-inquiries', $this->payload([
                'email' => "visitor{$index}@example.com",
                'subject' => "Consulta {$index}",
                'message' => "Mensaje suficientemente largo número {$index}.",
            ]))->assertCreated();
        }
        $this->postJson('/api/v1/contact-inquiries', $this->payload(['email' => 'sixth@example.com']))->assertTooManyRequests();
    }

    public function test_repeated_submission_token_is_idempotent_and_not_notified_twice(): void
    {
        Mail::fake(); config(['contact.notification_email' => 'admin@example.com']);
        $payload = $this->payload();
        $first = $this->postJson('/api/v1/contact-inquiries', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/contact-inquiries', $payload)->assertOk();
        $this->assertSame($first->json('reference'), $second->json('reference'));
        $this->assertDatabaseCount('contact_inquiries', 1);
        Mail::assertQueued(NewContactInquiryMail::class, 1);
    }

    public function test_duplicate_hash_blocks_immediate_duplicate_with_new_token(): void
    {
        $first = $this->postJson('/api/v1/contact-inquiries', $this->payload())->assertCreated();
        $second = $this->postJson('/api/v1/contact-inquiries', $this->payload())->assertOk();
        $this->assertSame($first->json('reference'), $second->json('reference'));
        $this->assertDatabaseCount('contact_inquiries', 1);
    }

    public function test_public_response_exposes_no_internal_fields(): void
    {
        $response = $this->postJson('/api/v1/contact-inquiries', $this->payload())->assertCreated();
        foreach (['assigned_to', 'submission_token', 'duplicate_hash', 'ip_hash', 'notes', 'history'] as $field) {
            $response->assertJsonMissingPath($field);
        }
    }

    public function test_notification_uses_configuration_from_and_reply_to_after_commit(): void
    {
        Mail::fake();
        config([
            'contact.notification_email' => 'contact-admin@example.com',
            'contact.from_name' => 'LubriStore Contacto',
            'mail.from.address' => 'no-reply@lubristore.test',
        ]);
        $this->postJson('/api/v1/contact-inquiries', $this->payload())->assertCreated();
        Mail::assertQueued(NewContactInquiryMail::class, function (NewContactInquiryMail $mail) {
            $mail->build();
            return $mail->hasTo('contact-admin@example.com')
                && $mail->hasFrom('no-reply@lubristore.test')
                && $mail->hasReplyTo('visitor@example.com');
        });
    }

    public function test_empty_recipient_or_mail_failure_never_removes_inquiry_or_logs_pii(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/contact-inquiries', $this->payload())->assertCreated();
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('contact_inquiries', 1);

        Log::spy();
        config(['contact.notification_email' => 'admin@example.com']);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Simulated transport failure'));
        $payload = $this->payload(['email' => 'private@example.com', 'subject' => 'Segundo asunto', 'message' => 'Contenido personal que no debe aparecer en logs.']);
        $this->postJson('/api/v1/contact-inquiries', $payload)->assertCreated();
        $this->assertDatabaseCount('contact_inquiries', 2);
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) use ($payload) {
            $encoded = json_encode([$message, $context]);
            return ! str_contains($encoded, $payload['email']) && ! str_contains($encoded, $payload['message']);
        });
    }

    public function test_visitors_and_customers_cannot_access_admin_api_but_admin_can(): void
    {
        $inquiry = $this->inquiry();
        $this->getJson('/api/v1/admin/contact-inquiries')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->getJson('/api/v1/admin/contact-inquiries')->assertForbidden();
        $this->getJson('/api/v1/admin/contact-inquiries/'.$inquiry->public_id)->assertForbidden();
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/contact-inquiries')->assertOk();
        $this->getJson('/api/v1/admin/contact-inquiries/'.$inquiry->public_id)->assertOk()->assertJsonPath('data.public_id', $inquiry->public_id);
    }

    public function test_admin_list_supports_search_filters_sort_and_pagination(): void
    {
        $admin = $this->admin(); Sanctum::actingAs($admin);
        for ($index = 0; $index < 16; $index++) $this->inquiry(['name' => "Persona {$index}", 'email' => "person{$index}@example.com", 'subject' => "Asunto {$index}"]);
        $special = $this->inquiry(['name' => 'Nombre único', 'email' => 'unique@example.com', 'subject' => 'Filtro especial', 'status' => ContactInquiry::IN_ATTENTION, 'assigned_to' => $admin->id]);
        $this->getJson('/api/v1/admin/contact-inquiries')->assertOk()->assertJsonPath('meta.current_page', 1)->assertJsonCount(15, 'data');
        $this->getJson('/api/v1/admin/contact-inquiries?search=Filtro%20especial&status=in_attention&assigned_to='.$admin->id.'&sort=activity_asc')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $special->public_id);
    }

    public function test_counter_includes_pending_and_in_attention_but_excludes_archived(): void
    {
        Sanctum::actingAs($this->admin());
        $this->inquiry(['status' => ContactInquiry::PENDING]);
        $this->inquiry(['status' => ContactInquiry::IN_ATTENTION]);
        $this->inquiry(['status' => ContactInquiry::ATTENDED]);
        $this->inquiry(['status' => ContactInquiry::PENDING, 'archived_at' => now()]);
        $this->getJson('/api/v1/admin/contact-inquiries/pending-count')->assertOk()->assertJsonPath('count', 2);
    }

    public function test_only_real_admin_can_be_assigned_and_assignment_is_historical(): void
    {
        $admin = $this->admin(); $customer = User::factory()->create(['role' => 'customer']); $inquiry = $this->inquiry(); Sanctum::actingAs($admin);
        $url = '/api/v1/admin/contact-inquiries/'.$inquiry->public_id.'/assignment';
        $this->patchJson($url, ['assigned_to' => $customer->id])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->patchJson($url, ['assigned_to' => $admin->id])->assertOk()->assertJsonPath('data.assignee.id', $admin->id);
        $this->assertDatabaseHas('contact_inquiry_histories', ['contact_inquiry_id' => $inquiry->id, 'event_type' => 'assigned', 'actor_id' => $admin->id]);
        $this->patchJson($url, ['assigned_to' => null])->assertOk()->assertJsonPath('data.assignee', null);
    }

    public function test_status_transitions_update_dates_preserve_them_on_reopen_and_record_history(): void
    {
        Sanctum::actingAs($this->admin()); $inquiry = $this->inquiry(); $url = '/api/v1/admin/contact-inquiries/'.$inquiry->public_id.'/status';
        $this->patchJson($url, ['status' => ContactInquiry::ATTENDED])->assertUnprocessable();
        $this->patchJson($url, ['status' => ContactInquiry::IN_ATTENTION])->assertOk();
        $attentionAt = $inquiry->refresh()->attention_started_at;
        $this->patchJson($url, ['status' => ContactInquiry::ATTENDED])->assertOk();
        $attendedAt = $inquiry->refresh()->attended_at;
        $this->patchJson($url, ['status' => ContactInquiry::CLOSED])->assertOk();
        $closedAt = $inquiry->refresh()->closed_at;
        $this->patchJson($url, ['status' => ContactInquiry::IN_ATTENTION])->assertOk();
        $this->assertTrue($inquiry->refresh()->attention_started_at->equalTo($attentionAt));
        $this->assertTrue($inquiry->attended_at->equalTo($attendedAt));
        $this->assertTrue($inquiry->closed_at->equalTo($closedAt));
        $this->assertSame(4, ContactInquiryHistory::where('contact_inquiry_id', $inquiry->id)->where('event_type', 'status_changed')->count());
    }

    public function test_internal_note_records_author_and_is_not_editable_or_public(): void
    {
        $admin = $this->admin(); Sanctum::actingAs($admin); $inquiry = $this->inquiry();
        $url = '/api/v1/admin/contact-inquiries/'.$inquiry->public_id.'/notes';
        $this->postJson($url, ['body' => 'Nota exclusivamente interna.'])->assertCreated()->assertJsonPath('data.notes.0.author.id', $admin->id);
        $this->assertDatabaseHas('contact_inquiry_notes', ['contact_inquiry_id' => $inquiry->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('contact_inquiry_histories', ['event_type' => 'note_added']);
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => in_array('PUT', $route->methods()) && str_contains($route->uri(), 'contact-inquiries') && str_contains($route->uri(), 'notes')));
    }

    public function test_archive_and_restore_preserve_inquiry_notes_and_history_without_delete_route(): void
    {
        $admin = $this->admin(); Sanctum::actingAs($admin); $inquiry = $this->inquiry();
        $this->postJson('/api/v1/admin/contact-inquiries/'.$inquiry->public_id.'/notes', ['body' => 'Nota conservada'])->assertCreated();
        $this->postJson('/api/v1/admin/contact-inquiries/'.$inquiry->public_id.'/archive')->assertOk();
        $this->assertNotNull($inquiry->refresh()->archived_at); $this->assertDatabaseCount('contact_inquiries', 1); $this->assertDatabaseCount('contact_inquiry_notes', 1);
        $this->postJson('/api/v1/admin/contact-inquiries/'.$inquiry->public_id.'/restore')->assertOk();
        $this->assertNull($inquiry->refresh()->archived_at);
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => in_array('DELETE', $route->methods()) && str_contains($route->uri(), 'contact-inquiries')));
    }

    public function test_email_and_whatsapp_actions_are_recorded_as_opened_not_sent(): void
    {
        Sanctum::actingAs($this->admin()); $inquiry = $this->inquiry(['phone' => '+51 999 111 222']); $base = '/api/v1/admin/contact-inquiries/'.$inquiry->public_id.'/actions/';
        $this->postJson($base.'email')->assertOk()->assertJsonPath('url', fn ($url) => str_starts_with($url, 'mailto:'));
        $this->postJson($base.'whatsapp')->assertOk()->assertJsonPath('url', fn ($url) => str_starts_with($url, 'https://wa.me/51999111222'));
        $events = ContactInquiryHistory::where('contact_inquiry_id', $inquiry->id)->pluck('event_type');
        $this->assertTrue($events->contains('email_client_opened')); $this->assertTrue($events->contains('whatsapp_opened'));
        $this->assertFalse($events->contains(fn ($event) => str_contains($event, 'sent') || str_contains($event, 'delivered')));
    }

    public function test_whatsapp_requires_a_valid_phone(): void
    {
        Sanctum::actingAs($this->admin()); $inquiry = $this->inquiry(['phone' => null]);
        $this->postJson('/api/v1/admin/contact-inquiries/'.$inquiry->public_id.'/actions/whatsapp')->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    private function payload(array $overrides = []): array
    {
        $token = (string) Str::uuid();
        $context = app(ContactFormSecurity::class)->context($token);
        return array_merge([
            'name' => '  Visitante   Ejemplo ',
            'email' => 'VISITOR@EXAMPLE.COM',
            'phone' => '+51 999 111 222',
            'subject' => ' Consulta de lubricante ',
            'message' => "Necesito información sobre un producto.\r\nGracias.",
            'submission_token' => $token,
            'website' => '',
        ], $context, $overrides);
    }

    private function inquiry(array $overrides = []): ContactInquiry
    {
        return ContactInquiry::create(array_merge([
            'public_id' => (string) Str::uuid(), 'name' => 'Persona', 'email' => Str::uuid().'@example.com', 'phone' => null,
            'subject' => 'Consulta', 'message' => 'Mensaje suficientemente largo.', 'status' => ContactInquiry::PENDING,
            'submission_token' => (string) Str::uuid(), 'duplicate_hash' => hash('sha256', Str::uuid()), 'source' => 'web', 'last_activity_at' => now(),
        ], $overrides));
    }

    private function admin(): User { return User::factory()->create(['role' => 'admin']); }
}
