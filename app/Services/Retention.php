<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Retention
{
    public function prune(): int
    {
        // Expected API failures are not logged; also remove old local exception logs after downtime.
        foreach (glob(storage_path('logs/laravel*.log')) ?: [] as $path) {
            if (is_file($path) && filemtime($path) <= now()->subDays(config('youtube.retention_days'))->timestamp) {
                unlink($path);
            }
        }

        return DB::transaction(function () {
            $count = 0;
            // Runs also contain API page tokens and API-derived identifiers. Cascades remove their items.
            foreach (['observations', 'videos', 'channels', 'fetch_runs'] as $table) {
                $count += DB::table($table)->where('expires_at', '<=', now())->delete();
            }
            DB::table('api_days')->where('day', '<', now()->subDays(28)->toDateString())->delete();
            // Retain only references explicitly used by the user; automatically discovered IDs expire too.
            $count += DB::table('targets')->where('watched', false)->whereNull('purpose')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('videos')->whereColumn('videos.target_id', 'targets.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('channels')->whereColumn('channels.target_id', 'targets.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('run_items')->whereColumn('run_items.target_id', 'targets.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('comparison_target')->whereColumn('comparison_target.target_id', 'targets.id'))->delete();

            return $count;
        });
    }
}
