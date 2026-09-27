<?php

$positiveInteger = static function (mixed $value, int $fallback, int $maximum): int {
    $validated = filter_var($value, FILTER_VALIDATE_INT);

    return $validated === false || $validated < 1 || $validated > $maximum
        ? $fallback
        : $validated;
};

$nonNegativeInteger = static function (mixed $value, int $fallback, int $maximum): int {
    $validated = filter_var($value, FILTER_VALIDATE_INT);

    return $validated === false || $validated < 0 || $validated > $maximum
        ? $fallback
        : $validated;
};

return [
    // Bounded fallbacks keep a malformed deployment variable from expiring or
    // extending reservations immediately (or for an unbounded period).
    'reservation_extension_minutes' => $positiveInteger(env('TREASURY_RESERVATION_EXTENSION_MINUTES', 120), 120, 1440),
    'payment_submission_future_tolerance_minutes' => $nonNegativeInteger(env('TREASURY_PAYMENT_SUBMISSION_FUTURE_TOLERANCE_MINUTES', 10), 10, 60),
    'observation_correction_minutes' => $positiveInteger(env('TREASURY_OBSERVATION_CORRECTION_MINUTES', 120), 120, 1440),
    'operation_number_max_length' => $positiveInteger(env('TREASURY_OPERATION_NUMBER_MAX_LENGTH', 100), 100, 100),
    'origin_bank_max_length' => $positiveInteger(env('TREASURY_ORIGIN_BANK_MAX_LENGTH', 120), 120, 120),
    'decision_reason_max_length' => $positiveInteger(env('TREASURY_DECISION_REASON_MAX_LENGTH', 1000), 1000, 1000),
    'qr_disk' => env('TREASURY_QR_DISK', 'treasury_qr'),
    'qr_max_kilobytes' => $positiveInteger(env('TREASURY_QR_MAX_KILOBYTES', 2048), 2048, 2048),
    'bank_account_min_length' => $positiveInteger(env('TREASURY_BANK_ACCOUNT_MIN_LENGTH', 6), 6, 30),
    'bank_account_max_length' => $positiveInteger(env('TREASURY_BANK_ACCOUNT_MAX_LENGTH', 30), 30, 50),
];
