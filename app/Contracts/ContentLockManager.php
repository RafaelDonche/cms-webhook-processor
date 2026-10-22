<?php

declare(strict_types=1);

namespace App\Contracts;

interface ContentLockManager
{
    /**
     * Blocks until the current transaction exclusively holds every given content, including contents
     * that do not exist yet. The locks are released when the transaction commits or rolls back.
     *
     * @param  list<string>  $externalIds
     */
    public function lockForCurrentTransaction(array $externalIds): void;
}
