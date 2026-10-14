<?php

use App\Services\Retention;
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
        $run = $collector->refreshWatch((bool) $this->option('daily'));
    } catch (ApiFailure $e) {
        $this->error(Presentation::error($e->reason));

        return 1;
    }
    $this->info("取得実行 #{$run->id}: {$run->status} / mode={$run->source}");

    return $run->status === 'complete' ? 0 : 1;
})->purpose('ウォッチ対象だけを取得（キーワード検索は行わない）');

Schedule::command('youtube:prune')->hourly()->withoutOverlapping();
Schedule::command('youtube:refresh-watch --daily')->dailyAt('09:00')->timezone('Asia/Tokyo')->withoutOverlapping();
