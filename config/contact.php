<?php

return [
    'notification_email' => env('CONTACT_NOTIFICATION_EMAIL'),
    'from_name' => env('CONTACT_FROM_NAME', env('APP_NAME')),
    'retention_months' => (int) env('CONTACT_RETENTION_MONTHS', 24),
    'duplicate_minutes' => 10,
    'minimum_fill_seconds' => 3,
    'form_context_minutes' => 120,
];
