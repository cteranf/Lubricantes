<?php

use App\Support\EmailVerificationSettings;

return [
    'expire' => EmailVerificationSettings::expiration(env('EMAIL_VERIFICATION_EXPIRE', 60)),
    'mailer' => env('EMAIL_VERIFICATION_MAILER', 'smtp'),
    'queue_connection' => 'email_verification',
    'queue' => 'email-verification',
    'resend' => [
        'ip_max_attempts' => 20,
        'email_max_attempts' => 3,
        'decay_seconds' => 3600,
    ],
];
