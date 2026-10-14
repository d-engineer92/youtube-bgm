<?php

namespace App\Services\Discovery;

use App\Models\Exploration;
use App\Services\Retention;
use App\Services\YouTube\ApiFailure;
use App\Services\YouTube\Collector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Runner
{
    // null: finished/paused; 0: enqueue next task; >0: bounded waiting.
    public function step(string $id): ?int
    {
        app(Retention::class)->prune();
        $lock = Cache::lock('exploration:'.$id, config('discovery.lease_seconds'));
        if (! $lock->get()) {
            return 30;
        }
        $previousMode = config('youtube.mode');
        try {
            $run = Exploration::find($id);
            if (! $run || ! in_array($run->status, ['queued', 'running'], true) || $run->expires_at <= now()) {
                return null;
            }
            config(['youtube.mode' => $run->source]);
            if ($run->stop_requested) {
                $run->update(['status' => 'paused', 'stop_reason' => 'halt_requested']);

                return null;
            }
            $task = DB::transaction(function () use ($run) {
                $task = $run->tasks()->whereIn('status', ['pending', 'running'])->lockForUpdate()->first();
                if (! $task || ($task->lease_until && $task->lease_until > now())) {
                    return $task ? false : null;
                }
                if ($task->status === 'running' || $run->in_flight) {
                    $run->update(['consumption_uncertain' => true, 'in_flight' => false]);
                }
                $task->update(['status' => 'running', 'claim' => (string) Str::uuid(), 'lease_until' => now()->addSeconds(config('discovery.lease_seconds')), 'attempts' => $task->attempts + 1]);
                $run->update(['status' => 'running', 'heartbeat_at' => now()]);

                return $task;
            });
            if ($task === false) {
                return 30;
            }
            if (! $task) {
                $partial = $run->tasks()->whereNotIn('status', ['complete', 'empty'])->exists();
                $run->update(['status' => $partial ? 'partial' : 'complete', 'finished_at' => now()]);

                return null;
            }
            try {
                $fetch = app(Collector::class)->collectTask($task);
                $error = $fetch->error_code ?? $fetch->items()->where('status', 'failed')->value('error_code');
                $resolved = $fetch->pages > 0 && ! $fetch->items()->whereIn('status', ['pending', 'failed'])->exists();
                $status = $resolved ? ($fetch->search_hits === 0 ? 'empty' : ($fetch->items()->where('status', 'unavailable')->exists() ? 'partial' : 'complete')) : ($fetch->items()->where('status', 'success')->exists() ? 'partial' : 'failed');
            } catch (ApiFailure $e) {
                $error = $e->reason;
                $status = 'failed';
            } catch (\Throwable) {
                // API bodies/credentials must not enter failed-job exception payloads.
                $error = 'worker_error';
                $status = 'partial';
                $run->update(['consumption_uncertain' => true]);
            }
            $task->update(['status' => $status, 'error_code' => $error, 'lease_until' => null, 'claim' => null]);
            if ($error === 'busy') {
                $run->increment('busy_attempts');
                if ($run->busy_attempts < 10) {
                    $task->update(['status' => 'pending']);

                    return 30;
                }
            }
            if (in_array($error, ['key_missing', 'access_denied', 'daily_limit', 'quota_exceeded', 'run_limit', 'halt_requested', 'busy', 'worker_error', 'expired_run'], true) || $run->fresh()->stop_requested) {
                $run->update(['status' => 'paused', 'stop_reason' => $error ?? 'halt_requested', 'heartbeat_at' => now()]);

                return null;
            }
            $run->update(['heartbeat_at' => now(), 'busy_attempts' => 0]);

            return 0;
        } finally {
            config(['youtube.mode' => $previousMode]);
            $lock->release();
        }
    }
}
