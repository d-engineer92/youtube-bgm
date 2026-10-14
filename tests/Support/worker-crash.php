<?php

// Standalone process tests: run serially with PHPUnit/browser tests, only in the guarded test container.
use App\Models\Observation;
use App\Services\Discovery\Planner;
use App\Services\YouTube\ApiCaller;
use App\Services\YouTube\ApiFailure;
use App\Services\YouTube\DemoGateway;
use App\Services\YouTube\Gateway;
use App\Support\DatabasePolicy;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DatabasePolicy::assertTestConnection(DB::connection(), app()->environment());
config(['youtube.mode' => 'demo', 'cache.default' => 'database']);
$app->make(Kernel::class)->call('migrate:fresh', ['--force' => true]);

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$run = app(Planner::class)->start('en-US', (string) Str::uuid());
DB::disconnect();
$pid = pcntl_fork();
if ($pid === 0) {
    DB::purge();
    app()->instance(Gateway::class, new class implements Gateway
    {
        public function fetch(string $method, array $parameters): array
        {
            if ($method === 'videos') {
                posix_kill(getmypid(), SIGKILL);
            }

            return (new DemoGateway)->fetch($method, $parameters);
        }
    });
    Queue::connection('database')->pop()->fire();
    exit(1);
}
pcntl_waitpid($pid, $status);
check(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'Worker was not killed at the detail call');
DB::purge();
$task = $run->tasks()->first();
$fetchId = $task->fetch_run_id;
check($task->fetchRun->pages === 1 && $task->status === 'running', 'Search page was not committed before SIGKILL');
check($run->fresh()->in_flight, 'Uncertain call reservation was not preserved');
check(DB::table('jobs')->whereNotNull('reserved_at')->count() === 1, 'Database queue did not retain interrupted job');
// Advance only the test clock past production retry/lease durations; no production timers are shortened.
Carbon::setTestNow(now()->addSeconds(181));
Queue::connection('database')->pop()->fire();
check($task->fresh()->fetch_run_id === $fetchId, 'Recovery changed FetchRun');
check($task->fresh()->status === 'complete', 'Recovery did not finish the same task');
check($run->fresh()->search_calls === 1, 'Recovery repeated the saved search page');
check($run->fresh()->consumption_uncertain, 'Uncertain consumption was not disclosed');
check(Observation::where('fetch_run_id', $fetchId)->count() === 5, 'Recovery duplicated or missed observations');
echo "PASS: actual SIGKILL, PostgreSQL queue redelivery, same task/FetchRun, details only, uncertain consumption retained\n";

// Two independent processes reserve the same run budget concurrently.
$budget = app(Planner::class)->start('en-US', (string) Str::uuid());
$budget->update(['read_limit' => 1]);
$children = [];
DB::disconnect();
for ($i = 0; $i < 2; $i++) {
    $child = pcntl_fork();
    if ($child === 0) {
        DB::purge();
        $api = app(ApiCaller::class);
        $api->setExploration($budget->id);
        $api->reset();
        try {
            $api->call('videos', ['id' => 'demoVideo01']);
        } catch (ApiFailure $e) {
            check($e->reason === 'run_limit', 'Concurrent process failed for another reason');
        }
        exit(0);
    }
    $children[] = $child;
}
foreach ($children as $child) {
    pcntl_waitpid($child, $status);
    check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Concurrent budget child failed');
}
DB::purge();
check($budget->fresh()->read_calls === 1, 'Concurrent reservations exceeded run limit');
echo "PASS: concurrent PostgreSQL call reservations respect persistent run budget after reset\n";
Carbon::setTestNow();
