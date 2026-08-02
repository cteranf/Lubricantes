<?php

namespace App\Services;

use App\Mail\NewContactInquiryMail;
use App\Models\ContactInquiry;
use App\Models\ContactInquiryHistory;
use App\Models\ContactInquiryNote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactInquiryService
{
    public function __construct(private ContactFormSecurity $security) {}

    public function create(array $data, ?User $user, ?string $ip): array
    {
        if ($existing = ContactInquiry::where('submission_token', $data['submission_token'])->first()) {
            return ['inquiry' => $existing, 'created' => false];
        }

        $duplicateHash = $this->security->duplicateHash($data['email'], $data['subject'], $data['message']);
        $recent = ContactInquiry::where('duplicate_hash', $duplicateHash)
            ->where('created_at', '>=', now()->subMinutes((int) config('contact.duplicate_minutes', 10)))
            ->latest()->first();
        if ($recent) return ['inquiry' => $recent, 'created' => false];

        try {
            $inquiry = DB::transaction(function () use ($data, $user, $ip, $duplicateHash) {
                $now = now();
                $inquiry = ContactInquiry::create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user?->id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => ($data['phone'] ?? null) ?: null,
                    'subject' => $data['subject'],
                    'message' => $data['message'],
                    'status' => ContactInquiry::PENDING,
                    'submission_token' => $data['submission_token'],
                    'duplicate_hash' => $duplicateHash,
                    'source' => 'web',
                    'ip_hash' => $this->security->ipHash($ip),
                    'last_activity_at' => $now,
                ]);
                $this->history($inquiry, null, 'created', null, ContactInquiry::PENDING);
                return $inquiry;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueConflict($exception)) {
                $existing = ContactInquiry::where('submission_token', $data['submission_token'])->first();
                if ($existing) return ['inquiry' => $existing, 'created' => false];
            }
            throw $exception;
        }

        $this->scheduleNotification($inquiry);
        return ['inquiry' => $inquiry, 'created' => true];
    }

    public function changeStatus(ContactInquiry $inquiry, User $actor, string $status): ContactInquiry
    {
        return DB::transaction(function () use ($inquiry, $actor, $status) {
            $inquiry = ContactInquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            if ($inquiry->status === $status) return $inquiry;
            if (! $inquiry->canTransitionTo($status)) {
                throw ValidationException::withMessages(['status' => ['La transición de estado solicitada no está permitida.']]);
            }
            $from = $inquiry->status;
            $updates = ['status' => $status, 'last_activity_at' => now()];
            if ($status === ContactInquiry::IN_ATTENTION && ! $inquiry->attention_started_at) $updates['attention_started_at'] = now();
            if ($status === ContactInquiry::ATTENDED && ! $inquiry->attended_at) $updates['attended_at'] = now();
            if ($status === ContactInquiry::CLOSED && ! $inquiry->closed_at) $updates['closed_at'] = now();
            $inquiry->update($updates);
            $this->history($inquiry, $actor, 'status_changed', $from, $status);
            return $inquiry->fresh();
        });
    }

    public function assign(ContactInquiry $inquiry, User $actor, ?User $assignee): ContactInquiry
    {
        return DB::transaction(function () use ($inquiry, $actor, $assignee) {
            $inquiry = ContactInquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            if ($inquiry->assigned_to === $assignee?->id) return $inquiry;
            $previous = $inquiry->assigned_to;
            $inquiry->update(['assigned_to' => $assignee?->id, 'last_activity_at' => now()]);
            $this->history($inquiry, $actor, $assignee ? 'assigned' : 'unassigned', null, null, [
                'previous_assignee_id' => $previous,
                'assignee_id' => $assignee?->id,
            ]);
            return $inquiry->fresh();
        });
    }

    public function addNote(ContactInquiry $inquiry, User $actor, string $body): ContactInquiryNote
    {
        return DB::transaction(function () use ($inquiry, $actor, $body) {
            $inquiry = ContactInquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $note = $inquiry->notes()->create(['user_id' => $actor->id, 'body' => trim($body)]);
            $inquiry->update(['last_activity_at' => now()]);
            $this->history($inquiry, $actor, 'note_added', null, null, ['note_id' => $note->id]);
            return $note;
        });
    }

    public function archive(ContactInquiry $inquiry, User $actor): ContactInquiry
    {
        return $this->setArchived($inquiry, $actor, true);
    }

    public function restore(ContactInquiry $inquiry, User $actor): ContactInquiry
    {
        return $this->setArchived($inquiry, $actor, false);
    }

    public function registerExternalAction(ContactInquiry $inquiry, User $actor, string $channel): string
    {
        return DB::transaction(function () use ($inquiry, $actor, $channel) {
            $inquiry = ContactInquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            if ($channel === 'email') {
                $url = 'mailto:'.rawurlencode($inquiry->email).'?subject='.rawurlencode('Consulta LubriStore '.$inquiry->public_id.' — '.$inquiry->subject);
                $event = 'email_client_opened';
            } elseif ($channel === 'whatsapp') {
                $phone = preg_replace('/\D+/', '', (string) $inquiry->phone);
                if (strlen($phone) < 6 || strlen($phone) > 15) {
                    throw ValidationException::withMessages(['phone' => ['La consulta no tiene un teléfono válido para WhatsApp.']]);
                }
                $message = 'Hola '.$inquiry->name.', nos comunicamos por tu consulta '.$inquiry->public_id.': '.$inquiry->subject;
                $url = 'https://wa.me/'.$phone.'?text='.rawurlencode($message);
                $event = 'whatsapp_opened';
            } else {
                throw ValidationException::withMessages(['channel' => ['Canal no permitido.']]);
            }
            $inquiry->update(['last_activity_at' => now()]);
            $this->history($inquiry, $actor, $event);
            return $url;
        });
    }

    private function setArchived(ContactInquiry $inquiry, User $actor, bool $archive): ContactInquiry
    {
        return DB::transaction(function () use ($inquiry, $actor, $archive) {
            $inquiry = ContactInquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            if ($archive === (bool) $inquiry->archived_at) return $inquiry;
            $inquiry->update(['archived_at' => $archive ? now() : null, 'last_activity_at' => now()]);
            $this->history($inquiry, $actor, $archive ? 'archived' : 'restored');
            return $inquiry->fresh();
        });
    }

    private function history(ContactInquiry $inquiry, ?User $actor, string $event, ?string $from = null, ?string $to = null, ?array $metadata = null): void
    {
        ContactInquiryHistory::create([
            'contact_inquiry_id' => $inquiry->id,
            'actor_id' => $actor?->id,
            'event_type' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    private function scheduleNotification(ContactInquiry $inquiry): void
    {
        $recipient = trim((string) config('contact.notification_email'));
        if ($recipient === '') {
            Log::warning('Contact inquiry notification skipped: recipient is not configured.', ['public_id' => $inquiry->public_id]);
            return;
        }
        DB::afterCommit(function () use ($inquiry, $recipient) {
            try {
                Mail::to($recipient)->queue(new NewContactInquiryMail($inquiry));
            } catch (\Throwable $exception) {
                Log::warning('Contact inquiry notification could not be queued.', [
                    'public_id' => $inquiry->public_id,
                    'exception' => $exception::class,
                ]);
            }
        });
    }

    private function isUniqueConflict(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        return in_array($state, ['23000', '23505', '19'], true)
            || str_contains(mb_strtolower($exception->getMessage()), 'unique');
    }
}
