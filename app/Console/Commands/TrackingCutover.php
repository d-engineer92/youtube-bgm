<?php

namespace App\Console\Commands;

use App\Services\Tracking\Cutover;
use Illuminate\Console\Command;

class TrackingCutover extends Command
{
    protected $signature = 'youtube:tracking-cutover {--expected=} {--operation=} {--execute} {--acceptance=}';

    protected $description = 'Explicit one-time cutover; dry-run unless --execute and a matching acceptance manifest are supplied';

    public function handle(Cutover $cutover): int
    {
        if ($this->option('execute') && ! app()->environment('testing')) {
            $path = $this->option('acceptance');
            $manifest = $path && is_file($path) ? json_decode(file_get_contents($path), true) : null;
            $head = trim((string) shell_exec('git rev-parse HEAD'));
            if (! $manifest || ($manifest['commit'] ?? null) !== $head || ($manifest['database'] ?? null) !== 'youtube_bgm_test' || ($manifest['driver'] ?? null) !== 'pgsql' || ($manifest['spec_reviewed'] ?? false) !== true || ($manifest['quiesced'] ?? false) !== true || ($manifest['outputs_checked'] ?? false) !== true || count(array_intersect($manifest['passed'] ?? [], range(1, 9))) !== 9) {
                $this->error('Refusing erase: matching P0/G01-G09 acceptance, reviewed spec, quiesced services and managed output check are required.');

                return self::FAILURE;
            }
        }
        $result = $cutover->run((string) $this->option('expected'), (string) $this->option('operation'), (bool) $this->option('execute'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
