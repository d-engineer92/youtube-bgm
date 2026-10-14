<?php

namespace App\Console\Commands;

use App\Support\DatabasePolicy;
use Illuminate\Console\Command;

class ServeBrowserTests extends Command
{
    protected $signature = 'bgm:browser-test-server';

    protected $description = 'Serve demo browser tests using the verified disposable PostgreSQL DB';

    public function handle(): int
    {
        DatabasePolicy::assertTestConnection(app('db')->connection(), app()->environment());
        if (config('youtube.mode') !== 'demo') {
            throw new \RuntimeException('Browser tests require demo mode.');
        }
        // Verification precedes the first destructive operation.
        if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('serve', ['--host' => '0.0.0.0', '--port' => '8088', '--no-reload' => true]);
    }
}
