<?php

namespace Tests\Unit;

use App\Exceptions\PaymentLinkEvidenceException;
use PHPUnit\Framework\TestCase;

class PaymentLinkEvidenceExceptionTest extends TestCase
{
    /** @dataProvider validCategories */
    public function test_valid_categories_are_preserved(string $category): void
    {
        $exception = new PaymentLinkEvidenceException($category);

        $this->assertSame($category, $exception->category, 'Valid evidence category must remain unchanged.');
        $this->assertSame($category, $exception->getMessage(), 'Exception message must match the normalized category.');
    }

    public static function validCategories(): array
    {
        return [
            [PaymentLinkEvidenceException::REMOTE_RESOURCE_ABSENT],
            [PaymentLinkEvidenceException::REMOTE_EVIDENCE_INCOMPLETE],
            [PaymentLinkEvidenceException::CONTRADICTORY_EVIDENCE],
            [PaymentLinkEvidenceException::STALE_LOCAL_LINK],
            [PaymentLinkEvidenceException::UNKNOWN_CATEGORY],
        ];
    }

    /** @dataProvider invalidCategories */
    public function test_arbitrary_categories_are_replaced_without_leaking(string $input): void
    {
        $exception = new PaymentLinkEvidenceException($input);
        $serialized = serialize($exception);
        $persistibleContext = ['failure_reason' => $exception->category];

        $this->assertSame(PaymentLinkEvidenceException::UNKNOWN_CATEGORY, $exception->category, 'Unknown category must normalize to the generic category.');
        $this->assertSame(PaymentLinkEvidenceException::UNKNOWN_CATEGORY, $exception->getMessage(), 'Exception message must not retain arbitrary input.');
        $this->assertFalse(str_contains($exception->category, 'synthetic-arbitrary-sentinel'), 'Normalized category must not contain arbitrary input.');
        $this->assertFalse(str_contains($exception->getMessage(), 'synthetic-arbitrary-sentinel'), 'Exception message must not contain arbitrary input.');
        $this->assertFalse(str_contains($serialized, 'synthetic-arbitrary-sentinel'), 'Serialized exception must not contain arbitrary input.');
        $this->assertSame(PaymentLinkEvidenceException::UNKNOWN_CATEGORY, $persistibleContext['failure_reason'], 'Persistible context must contain only the normalized category.');
    }

    public static function invalidCategories(): array
    {
        return [
            'arbitrary' => ['synthetic-arbitrary-sentinel'],
            'empty' => [''],
            'spaces' => ['   '],
            'case differs' => ['REMOTE_RESOURCE_ABSENT'],
            'near match prefix' => ['prefix_remote_resource_absent'],
            'near match' => ['remote_resource_absent_extra'],
        ];
    }

    public function test_previous_exception_is_preserved_while_category_is_normalized(): void
    {
        $previous = new \RuntimeException('synthetic-safe-cause');
        $exception = new PaymentLinkEvidenceException('synthetic-arbitrary-sentinel', $previous);

        $this->assertSame($previous, $exception->getPrevious(), 'Original cause must be preserved.');
        $this->assertSame(PaymentLinkEvidenceException::UNKNOWN_CATEGORY, $exception->category, 'Arbitrary category must normalize when preserving a cause.');
        $this->assertSame(PaymentLinkEvidenceException::UNKNOWN_CATEGORY, $exception->getMessage(), 'Cause text must not replace the normalized public message.');
    }

    public function test_arbitrary_sentinel_is_absent_from_all_public_exception_representations(): void
    {
        $this->assertSentinelIsAbsent();

        $originalSetting = ini_get('zend.exception_ignore_args');
        $settingChanged = false;
        try {
            $result = @ini_set('zend.exception_ignore_args', '0');
            if ($result === false) {
                $this->assertSame($originalSetting, ini_get('zend.exception_ignore_args'), 'Runtime trace-argument setting must be explicitly confirmed unchanged when immutable.');
            } else {
                $settingChanged = true;
                $this->assertSame('0', ini_get('zend.exception_ignore_args'), 'Runtime trace-argument setting must take the requested value.');
                $this->assertSentinelIsAbsent();
            }
        } finally {
            if ($settingChanged && $originalSetting !== false) {
                @ini_set('zend.exception_ignore_args', $originalSetting);
            }
        }
    }

    private function assertSentinelIsAbsent(): void
    {
        $sentinel = 'synthetic-private-sentinel';
        $previous = new \RuntimeException('synthetic-safe-cause');
        $exception = new PaymentLinkEvidenceException($sentinel, $previous);
        $trace = json_encode($exception->getTrace(), JSON_THROW_ON_ERROR);
        $serialized = serialize($exception);

        $this->assertSame(PaymentLinkEvidenceException::UNKNOWN_CATEGORY, $exception->category, 'Arbitrary input must normalize to the generic category.');
        $this->assertSame($previous, $exception->getPrevious(), 'Previous exception must be preserved.');
        $this->assertFalse(str_contains($exception->category, $sentinel), 'Category must not expose arbitrary input.');
        $this->assertFalse(str_contains($exception->getMessage(), $sentinel), 'Message must not expose arbitrary input.');
        $this->assertFalse(str_contains($trace, $sentinel), 'Trace must not expose arbitrary input.');
        $this->assertFalse(str_contains($exception->getTraceAsString(), $sentinel), 'Trace string must not expose arbitrary input.');
        $this->assertFalse(str_contains($serialized, $sentinel), 'Serialization must not expose arbitrary input.');
    }
}
