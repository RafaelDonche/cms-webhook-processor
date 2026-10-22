<?php

declare(strict_types=1);

namespace App\Enums;

enum IgnoreReason: string
{
    case Duplicate = 'duplicate';
    case Stale = 'stale';

    public static function forVersions(int $acceptedVersion, int $eventVersion): self
    {
        return $eventVersion === $acceptedVersion ? self::Duplicate : self::Stale;
    }
}
