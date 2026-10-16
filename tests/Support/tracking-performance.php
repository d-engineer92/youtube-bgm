<?php

use App\Support\DatabasePolicy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    DatabasePolicy::assertTestConnection($app['db']->connection(), $app->environment());
    config(['youtube.mode' => 'demo']);
    if (DB::table('videos')->count() !== 1000 || DB::table('observations')->count() !== 28000) {
        throw new RuntimeException('G11 requires exactly 1000 distinct videos and 28000 observations.');
    }
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $kernel->handle(Request::create('/tracking'))->getContent();
    $times = [];
    $queries = [];
    $memory = [];
    for ($n = 0; $n < 10; $n++) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        memory_reset_peak_usage();
        $start = microtime(true);
        $request = Request::create('/tracking');
        $response = $kernel->handle($request);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Tracking endpoint failed');
        }
        $html = $response->getContent();
        $kernel->terminate($request, $response);
        $times[] = round((microtime(true) - $start) * 1000, 2);
        $queries[] = count(DB::getQueryLog());
        $memory[] = memory_get_peak_usage(true);
        DB::disableQueryLog();
        if (substr_count($html, 'class="panel tracking-row"') > 24) {
            throw new RuntimeException('Unpaged DOM');
        }
    }
    sort($times);
    $result = ['videos' => DB::table('videos')->count(), 'observations' => DB::table('observations')->count(), 'runs' => 10, 'scope' => 'HTTP kernel including middleware and view', 'p95_ms' => $times[9], 'max_queries' => max($queries), 'peak_mib' => round(max($memory) / 1048576, 2), 'target' => ['p95_ms' => 1000, 'max_queries' => 30, 'peak_mib' => 128]];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    exit($result['p95_ms'] <= 1000 && $result['max_queries'] <= 30 && $result['peak_mib'] <= 128 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
