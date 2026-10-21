<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const string TEST_DATABASE_SUFFIX = '_test';

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->guardAgainstNonTestDatabase($app);

        return $app;
    }

    private function guardAgainstNonTestDatabase(Application $app): void
    {
        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, self::TEST_DATABASE_SUFFIX)) {
            throw new RuntimeException(
                "Refusing to run tests against [{$database}]: the database name must end with ".self::TEST_DATABASE_SUFFIX.'.'
            );
        }
    }
}
