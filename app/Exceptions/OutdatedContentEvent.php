<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

final class OutdatedContentEvent extends LogicException
{
    public static function forVersion(string $externalId, int $currentVersion, int $eventVersion): self
    {
        return new self(
            "Content [{$externalId}] is at version {$currentVersion}; version {$eventVersion} cannot be applied."
        );
    }
}
