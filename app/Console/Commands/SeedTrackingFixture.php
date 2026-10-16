<?php

namespace App\Console\Commands;

use App\Services\Tracking\SyntheticFixture;
use Illuminate\Console\Command;

class SeedTrackingFixture extends Command
{
    protected $signature = 'bgm:tracking-fixture {--count=130} {--days=3}';

    protected $description = 'Synthetic distinct videos in the guarded test PostgreSQL database only';

    public function handle(SyntheticFixture $fixture): int
    {
        $fixture->seed((int) $this->option('count'), (int) $this->option('days'));
        $this->info('Synthetic fixture seeded; no API calls.');

        return self::SUCCESS;
    }
}
