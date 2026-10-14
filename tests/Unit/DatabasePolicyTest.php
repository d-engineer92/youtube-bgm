<?php

namespace Tests\Unit;

use App\Support\DatabasePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabasePolicyTest extends TestCase
{
    public static function refused(): array
    {
        return [
            [['driver' => 'sqlite'], 'testing'],
            [['database' => 'youtube_bgm'], 'testing'],
            [['database' => 'untrusted_test'], 'testing'],
            [['username' => 'bgm'], 'testing'],
            [['host' => 'db'], 'testing'],
            [['port' => 5433], 'testing'],
            [[], 'production'],
            [['read' => ['host' => 'db']], 'testing'],
        ];
    }

    private function allowed(): array
    {
        return ['driver' => 'pgsql', 'host' => 'db-test', 'port' => '5432', 'database' => 'youtube_bgm_test', 'username' => 'bgm_test'];
    }

    #[DataProvider('refused')]
    public function test_refuses_unsafe_effective_configuration_before_connecting(array $override, string $environment): void
    {
        $this->expectException(RuntimeException::class);
        DatabasePolicy::assertConfiguration(array_replace($this->allowed(), $override), true, $environment);
    }

    public function test_accepts_only_explicit_postgresql_test_tuple(): void
    {
        DatabasePolicy::assertConfiguration($this->allowed(), true, 'testing');
        $this->addToAssertionCount(1);
    }
}
