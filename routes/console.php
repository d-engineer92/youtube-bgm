<?php

use App\Jobs\UpdateTracking;
use App\Services\Retention;
use App\Services\Tracking\Dataset;
use App\Services\Tracking\Updater;
use App\Services\YouTube\ApiFailure;
use App\Services\YouTube\Collector;
use App\Support\Presentation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('youtube:prune', function (Retention $retention) {
    $this->info('削除した期限切れレコード: '.$retention->prune());
})->purpose('期限切れのAPIデータと取得実行を削除');

Artisan::command('youtube:refresh-watch {--daily : 同一日の実行を冪等にする}', function (Collector $collector) {
    try {
        if ($this->option('daily')) {
            (new UpdateTracking(Dataset::id(), config('youtube.mode')))->handle(app(Updater::class));
            $this->info('全追跡の期限到来バッチを確認しました。結果・未処理は変化一覧で確認してください。mode='.config('youtube.mode'));

            return 0;
        }
        $run = $collector->refreshWatch((bool) $this->option('daily'));
    } catch (ApiFailure $e) {
        $this->error(Presentation::error($e->reason));

        return 1;
    }
    $this->info("取得実行 #{$run->id}: {$run->status} / mode={$run->source}");

    return $run->status === 'complete' ? 0 : 1;
})->purpose('互換入口：--dailyは全追跡の期限到来、通常はお気に入りの明示更新/投稿確認');

Schedule::command('youtube:prune')->hourly()->withoutOverlapping();
Artisan::command('youtube:tracking-tick', function () {
    Dataset::heartbeat('scheduler');
    foreach (['live', 'demo'] as $source) {
        UpdateTracking::dispatch(Dataset::id(), $source)->onConnection('database')->onQueue('tracking');
    }
});
Schedule::command('youtube:tracking-tick')->everyFiveMinutes()->withoutOverlapping();
