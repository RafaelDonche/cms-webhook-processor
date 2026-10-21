<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CmsEventType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use stdClass;

final readonly class CmsEvent
{
    public ?stdClass $payload;

    public function __construct(
        public CmsEventType $type,
        public string $externalId,
        public int $version,
        public CarbonImmutable $timestamp,
        ?stdClass $payload = null,
    ) {
        if ($version < 1) {
            throw new InvalidArgumentException("Event version must be a positive integer, {$version} given.");
        }

        if ($type->carriesPayload() && $payload === null) {
            throw new InvalidArgumentException("A {$type->value} event requires a payload.");
        }

        $this->payload = $type->carriesPayload() ? $payload : null;
    }
}
