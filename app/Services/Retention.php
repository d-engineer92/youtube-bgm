<?php

namespace App\Services;

use App\Models\Exploration;
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
            foreach (Exploration::where('expires_at', '<=', now())->get() as $run) {
                foreach (['jobs', 'failed_jobs'] as $queue) {
                    DB::table($queue)->where('payload', 'like', '%'.$run->id.'%')->delete();
                }
                DB::table('fetch_runs')->whereIn('id', $run->tasks()->whereNotNull('fetch_run_id')->pluck('fetch_run_id'))->delete();
                $count += $run->tasks()->delete();
                $run->update(['status' => 'expired', 'stop_reason' => null, 'in_flight' => false]);
            }
            DB::table('failed_jobs')->where('failed_at', '<=', now()->subDays(28))->delete();
            DB::table('jobs')->where('created_at', '<=', now()->subDays(28)->timestamp)->delete();
            DB::table('cache')->where('expiration', '<=', now()->timestamp)->delete();
            DB::table('cache_locks')->where('expiration', '<=', now()->timestamp)->delete();
            // Runs also contain API page tokens and API-derived identifiers. Cascades remove their items.
            foreach (['observations', 'metadata_versions', 'videos', 'channels', 'fetch_runs'] as $table) {
                $count += DB::table($table)->where('expires_at', '<=', now())->delete();
            }
            DB::statement("WITH expired AS (UPDATE comparisons SET evidence=NULL, analysis=NULL, analysis_state='expired' WHERE evidence_expires_at <= ? RETURNING id), cleared AS (UPDATE analysis_revisions SET content=NULL WHERE comparison_id IN (SELECT id FROM expired) RETURNING comparison_id) UPDATE production_briefs SET content=content - 'reference' - 'avoid' WHERE comparison_id IN (SELECT id FROM expired)", [now()]);
            DB::table('tracking_slots')->where('scheduled_for', '<', now()->subDays(28))->delete();
            DB::table('api_days')->where('day', '<', now()->subDays(28)->toDateString())->delete();
            // Retain only references explicitly used by the user; automatically discovered IDs expire too.
            $count += DB::table('targets')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('tracking_targets')->whereColumn('tracking_targets.target_id', 'targets.id'))->where('watched', false)->whereNull('purpose')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('videos')->whereColumn('videos.target_id', 'targets.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('channels')->whereColumn('channels.target_id', 'targets.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('run_items')->whereColumn('run_items.target_id', 'targets.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('comparison_target')->whereColumn('comparison_target.target_id', 'targets.id'))->delete();

            return $count;
        });
    }
}
