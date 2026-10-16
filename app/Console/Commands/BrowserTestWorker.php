<?php

namespace App\Console\Commands;

use App\Support\DatabasePolicy;
use Illuminate\Console\Command;

class BrowserTestWorker extends Command
{
    protected $signature = 'bgm:browser-test-worker';

    protected $description = 'Run the queue worker only in the verified disposable browser-test DB';

    public function handle(): int
    {
        DatabasePolicy::assertTestConnection(app('db')->connection(), app()->environment());

        return $this->call('queue:work', ['connection' => 'database', '--queue' => 'tracking,default', '--sleep' => 1, '--timeout' => 120, '--tries' => 20]);
    }
}
