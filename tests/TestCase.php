<?php

namespace Tests;

use App\Support\DatabasePolicy;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static bool $reportedDatabase = false;

    public function createApplication()
    {
        $app = parent::createApplication();
        if (array_intersect(
            [RefreshDatabase::class, DatabaseMigrations::class, DatabaseTransactions::class, DatabaseTruncation::class],
            class_uses_recursive(static::class),
        )) {
            $identity = DatabasePolicy::assertTestConnection($app['db']->connection(), $app->environment());
            if (! self::$reportedDatabase) {
                fwrite(STDOUT, "\nDB verified: driver=pgsql database={$identity['database']} role={$identity['role']} PostgreSQL={$identity['version']}\n");
                self::$reportedDatabase = true;
            }
        }

        return $app;
    }
}
