<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentStatus: string
{
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Deleted = 'deleted';
}
