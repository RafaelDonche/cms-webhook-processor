<?php

declare(strict_types=1);

namespace Tests\Feature\Rules;

use App\Rules\Rfc3339Timestamp;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class Rfc3339TimestampTest extends TestCase
{
    #[DataProvider('validTimestamps')]
    public function test_accepts_rfc_3339_timestamps_with_a_time_zone(string $timestamp): void
    {
        $this->assertTrue($this->passes($timestamp));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validTimestamps(): array
    {
        return [
            'utc designator' => ['2026-01-01T10:00:00Z'],
            'positive offset' => ['2026-01-01T10:00:00+02:00'],
            'negative offset' => ['2026-01-01T10:00:00-03:00'],
            'milliseconds' => ['2026-01-01T10:00:00.123Z'],
            'microseconds' => ['2026-01-01T10:00:00.123456+05:30'],
            'nanoseconds' => ['2026-01-01T10:00:00.123456789Z'],
            'lowercase separators' => ['2026-01-01t10:00:00z'],
            'leap day' => ['2028-02-29T00:00:00Z'],
        ];
    }

    #[DataProvider('invalidTimestamps')]
    public function test_rejects_values_that_are_not_rfc_3339_timestamps_with_a_time_zone(mixed $timestamp): void
    {
        $this->assertFalse($this->passes($timestamp));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidTimestamps(): array
    {
        return [
            'missing time zone' => ['2026-01-01T10:00:00'],
            'space separator' => ['2026-01-01 10:00:00Z'],
            'date only' => ['2026-01-01'],
            'impossible date' => ['2026-02-30T10:00:00Z'],
            'impossible time' => ['2026-01-01T25:00:00Z'],
            'offset out of range' => ['2026-01-01T10:00:00+24:00'],
            'natural language' => ['yesterday'],
            'unix timestamp' => [1767261600],
        ];
    }

    private function passes(mixed $timestamp): bool
    {
        return Validator::make(['timestamp' => $timestamp], ['timestamp' => new Rfc3339Timestamp])->passes();
    }
}
