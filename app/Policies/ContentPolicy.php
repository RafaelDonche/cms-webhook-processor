<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Content;
use App\Models\User;

final class ContentPolicy
{
    public function manageDisablement(User $user, Content $content): bool
    {
        return $user->isAdmin();
    }
}
