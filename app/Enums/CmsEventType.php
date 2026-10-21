<?php

declare(strict_types=1);

namespace App\Enums;

enum CmsEventType: string
{
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case Delete = 'delete';

    public function resultingStatus(): ContentStatus
    {
        return match ($this) {
            self::Publish => ContentStatus::Published,
            self::Unpublish => ContentStatus::Unpublished,
            self::Delete => ContentStatus::Deleted,
        };
    }

    public function carriesPayload(): bool
    {
        return $this !== self::Delete;
    }
}
