<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\ContentLockManager;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class PostgresAdvisoryLockManager implements ContentLockManager
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function lockForCurrentTransaction(array $externalIds): void
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('Content locks can only be acquired inside a database transaction.');
        }

        foreach ($this->lockKeysInAcquisitionOrder($externalIds) as $lockKey) {
            $this->connection->select('select pg_advisory_xact_lock(?)', [$lockKey]);
        }
    }

    /**
     * Every batch acquires its keys in ascending order, so two batches can never wait on each other in a cycle.
     * Sorting the keys rather than the ids keeps that true when two ids hash to the same key.
     *
     * @param  list<string>  $externalIds
     * @return list<int>
     */
    private function lockKeysInAcquisitionOrder(array $externalIds): array
    {
        $lockKeys = array_values(array_unique(array_map(self::lockKey(...), $externalIds)));
        sort($lockKeys);

        return $lockKeys;
    }

    private static function lockKey(string $externalId): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('xxh64', $externalId, binary: true));

        return $unpacked[1];
    }
}
