<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

final class Rfc3339Timestamp implements ValidationRule
{
    private const string PATTERN = '/^(?<date>\d{4}-\d{2}-\d{2})T(?<time>\d{2}:\d{2}:\d{2})(\.\d{1,6})?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isValid($value)) {
            $fail('The :attribute field must be an RFC 3339 date-time with a time zone.');
        }
    }

    private function isValid(string $value): bool
    {
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            return false;
        }

        $localDateTime = "{$matches['date']} {$matches['time']}";
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $localDateTime);

        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $localDateTime;
    }
}
