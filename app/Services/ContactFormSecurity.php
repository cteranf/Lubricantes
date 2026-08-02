<?php

namespace App\Services;

class ContactFormSecurity
{
    public function context(string $submissionToken): array
    {
        $startedAt = time();
        return [
            'form_started_at' => $startedAt,
            'form_signature' => $this->signature($submissionToken, $startedAt),
        ];
    }

    public function validContext(string $submissionToken, int $startedAt, string $signature): bool
    {
        $age = time() - $startedAt;
        if ($age < (int) config('contact.minimum_fill_seconds', 3)) return false;
        if ($age > ((int) config('contact.form_context_minutes', 120) * 60)) return false;
        return hash_equals($this->signature($submissionToken, $startedAt), $signature);
    }

    public function ipHash(?string $ip): ?string
    {
        return $ip ? $this->hmac('ip|'.$ip) : null;
    }

    public function duplicateHash(string $email, string $subject, string $message): string
    {
        return $this->hmac('duplicate|'.mb_strtolower($email).'|'.mb_strtolower($subject).'|'.$message);
    }

    private function signature(string $submissionToken, int $startedAt): string
    {
        return $this->hmac('form|'.$submissionToken.'|'.$startedAt);
    }

    private function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
