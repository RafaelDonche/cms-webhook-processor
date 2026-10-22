<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\PostgresAdvisoryLockManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class PostgresAdvisoryLockManagerTest extends TestCase
{
    private const string LOCK_NOT_AVAILABLE = '55P03';

    public function test_blocks_other_transactions_from_locking_the_same_content_until_it_ends(): void
    {
        $holder = $this->openConnection('lock_holder');
        $contender = $this->openConnection('lock_contender');
        $holder->beginTransaction();
        (new PostgresAdvisoryLockManager($holder))->lockForCurrentTransaction(['content-1']);

        $this->assertFalse($this->tryLocking($contender, ['content-1']));

        $holder->commit();
        $this->assertTrue($this->tryLocking($contender, ['content-1']));
    }

    public function test_does_not_block_other_transactions_locking_different_contents(): void
    {
        $holder = $this->openConnection('lock_holder');
        $contender = $this->openConnection('lock_contender');
        $holder->beginTransaction();
        (new PostgresAdvisoryLockManager($holder))->lockForCurrentTransaction(['content-1']);

        $this->assertTrue($this->tryLocking($contender, ['content-2']));

        $holder->rollBack();
    }

    public function test_acquires_each_lock_once_in_the_same_order_whatever_the_input_order(): void
    {
        $connection = $this->openConnection('lock_holder');

        $firstOrder = $this->lockKeysRequested($connection, ['content-2', 'content-1', 'content-2']);
        $secondOrder = $this->lockKeysRequested($connection, ['content-1', 'content-2']);

        $this->assertCount(2, $firstOrder);
        $this->assertSame($firstOrder, $secondOrder);
    }

    public function test_refuses_to_lock_outside_a_transaction(): void
    {
        $locks = new PostgresAdvisoryLockManager($this->openConnection('lock_holder'));

        $this->expectException(LogicException::class);

        $locks->lockForCurrentTransaction(['content-1']);
    }

    private function openConnection(string $name): ConnectionInterface
    {
        $default = config()->string('database.default');
        config(["database.connections.{$name}" => config("database.connections.{$default}")]);
        $this->beforeApplicationDestroyed(static fn () => DB::purge($name));

        return DB::connection($name);
    }

    /**
     * @param  list<string>  $externalIds
     */
    private function tryLocking(ConnectionInterface $connection, array $externalIds): bool
    {
        $connection->beginTransaction();
        $connection->statement("set local lock_timeout = '100ms'");

        try {
            (new PostgresAdvisoryLockManager($connection))->lockForCurrentTransaction($externalIds);

            return true;
        } catch (QueryException $exception) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $exception->getCode());

            return false;
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @param  list<string>  $externalIds
     * @return list<mixed>
     */
    private function lockKeysRequested(ConnectionInterface $connection, array $externalIds): array
    {
        $lockKeys = [];
        DB::listen(static function (QueryExecuted $query) use (&$lockKeys): void {
            if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $lockKeys[] = $query->bindings[0];
            }
        });

        $connection->transaction(static fn () => (new PostgresAdvisoryLockManager($connection))->lockForCurrentTransaction($externalIds));

        return $lockKeys;
    }
}
