<?php

namespace App\Services\Tracking;

use App\Support\DatabasePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class Cutover
{
    public const TABLES = ['production_briefs', 'analysis_revisions', 'comparison_target', 'comparisons', 'observations', 'metadata_versions', 'tracking_slots', 'tracking_targets', 'videos', 'channels', 'run_items', 'exploration_tasks', 'explorations', 'exploration_keywords', 'fetch_runs', 'targets', 'jobs', 'failed_jobs', 'job_batches', 'sessions', 'cache', 'cache_locks', 'tracking_heartbeats'];

    public const PROTECTED = ['datasets', 'api_days', 'migrations'];

    public function run(string $expected, string $operation, bool $execute = false): array
    {
        if (! Str::isUuid($expected) || ! Str::isUuid($operation)) {
            throw new RuntimeException('Expected generation and operation must be UUIDs.');
        }
        $connection = DB::connection();
        if (app()->environment('testing')) {
            DatabasePolicy::assertTestConnection($connection, app()->environment());
        } else {
            $c = $connection->getConfig();
            if (! app()->runningInConsole() || ! app()->environment('local') || $c['driver'] !== 'pgsql' || $c['host'] !== 'db' || $c['database'] !== 'youtube_bgm' || $c['username'] !== 'bgm' || (string) $c['port'] !== '5432' || isset($c['read']) || isset($c['write'])) {
                throw new RuntimeException('Refusing cutover outside the local Compose allowlist.');
            }
            $identity = DB::selectOne('select current_database() as name, current_user as role, host(inet_server_addr()) as address');
            if ($identity->name !== 'youtube_bgm' || $identity->role !== 'bgm' || $identity->address !== gethostbyname('db')) {
                throw new RuntimeException('Unexpected effective PostgreSQL identity.');
            }
            if ($execute && ! app()->isDownForMaintenance()) {
                throw new RuntimeException('Maintenance mode and stopped Web/workers/scheduler are required.');
            }
        }

        return DB::transaction(function () use ($expected, $operation, $execute) {
            DB::select('select pg_advisory_xact_lock(4781101)');
            $current = DB::table('datasets')->where('active', true)->lockForUpdate()->sole();
            if ($current->cutover_at) {
                return ['state' => 'already_cut_over', 'dataset_id' => $current->id, 'counts' => []];
            }
            if ($expected !== $current->id || DB::table('datasets')->whereNotNull('cutover_at')->exists()) {
                throw new RuntimeException('Generation mismatch or cutover already completed; refusing another erase.');
            }
            $tables = collect(DB::select("select tablename from pg_tables where schemaname='public'"))->pluck('tablename')->all();
            if (array_diff($tables, array_merge(self::TABLES, self::PROTECTED))) {
                throw new RuntimeException('Unclassified table; refusing cutover.');
            }
            $foreign = DB::select("select conrelid::regclass::text as child, confrelid::regclass::text as parent from pg_constraint where contype='f' and confrelid in (select oid from pg_class where relnamespace='public'::regnamespace)");
            foreach ($foreign as $fk) {
                if (in_array($fk->parent, self::TABLES, true) && ! in_array($fk->child, self::TABLES, true)) {
                    throw new RuntimeException('Unclassified foreign-key dependency.');
                }
            }
            $counts = [];
            foreach (array_intersect(self::TABLES, $tables) as $table) {
                $counts[$table] = DB::table($table)->count();
            }
            if (! $execute) {
                return ['state' => 'dry_run', 'dataset_id' => $current->id, 'counts' => $counts];
            }
            $budget = DB::table('api_days')->orderBy('id')->get()->toJson();
            foreach (array_intersect(self::TABLES, $tables) as $table) {
                DB::table($table)->delete();
            }
            DB::table('datasets')->where('id', $current->id)->update(['active' => false]);
            $id = (string) Str::uuid();
            DB::table('datasets')->insert(['id' => $id, 'active' => true, 'cutover_operation' => $operation, 'cutover_at' => now(), 'created_at' => now()]);
            if ($budget !== DB::table('api_days')->orderBy('id')->get()->toJson()) {
                throw new RuntimeException('Budget changed.');
            }

            return ['state' => 'cut_over', 'dataset_id' => $id, 'counts' => $counts];
        });
    }
}
