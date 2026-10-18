<?php

namespace App\Support;

use App\Models\Target;
use App\Services\Tracking\Interval;

class Presentation
{
    public static function date($date): string
    {
        return $date ? $date->timezone('Asia/Tokyo')->format('Y-m-d H:i:s').' JST' : '未取得';
    }

    public static function number($number): string
    {
        return $number === null ? '未取得／非公開' : number_format($number);
    }

    public static function duration(?int $seconds): string
    {
        return $seconds === null ? '未取得' : sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    public static function status(string $status): string
    {
        return match ($status) {
            'queued' => '待機中', 'paused' => '停止中', 'empty' => '成功・0件', 'expired' => '結果期限切れ', 'skipped' => '未実施',
            'success', 'complete' => '今回取得成功', 'failed' => '取得失敗', 'partial' => '一部成功・失敗あり', 'pending' => '未取得', 'running' => '処理中／中断時は再試行可', 'unavailable' => 'APIから返却なし（非公開・削除等の理由は未確認）', default => '未確認',
        };
    }

    public static function error(?string $reason): string
    {
        return match ($reason) {
            null => '', 'cutover_required' => '新方式の安全な切替前です。取得は停止しています。', 'old_generation' => '旧世代の操作です。現在の一覧からやり直してください。', 'tracking_budget_reserved' => '全動画の追跡用に予算を予約しています。新規発見は待機中です。', 'key_missing' => 'APIキー未設定。サーバーの.envに設定してください。実APIの取得は行われていません。',
            'run_limit' => 'この探索の送信試行上限に達しました。途中結果を保存しました。',
            'halt_requested' => '停止を受け付けました。保存済み結果は残っています。',
            'worker_error' => 'ワーカーが中断しました。起動を確認して続きから再開してください。',
            'daily_limit' => 'このアプリの日次呼出上限に達しました。', 'quota_exceeded' => 'YouTubeのクォータ上限。翌日の割当またはCloud Consoleを確認してください。',
            'tracking_daily_limit' => '追跡の日次読取枠に達しました。新規・手動取得用の枠を残して、太平洋時間の翌日まで待機します。',
            'network_error' => '通信失敗／タイムアウト。回数を制限して再試行しました。', 'temporary_error' => 'APIの一時エラー。回数を制限して再試行しました。',
            'operation_limit' => '今回の処理時間・呼出回数上限。未取得分は再試行できます。', 'access_denied' => 'APIへのアクセス拒否。キーの制限とAPIの有効化を確認してください。',
            'invalid_request' => 'APIが取得条件を受け付けませんでした。', 'channel_unavailable' => 'チャンネル情報／投稿一覧を取得できませんでした。',
            'expired_run' => '取得実行の保存期限切れ。新しく検索・登録してください。', 'busy' => '別の取得処理が動いています。完了後に再試行してください。',
            'not_returned' => 'APIから対象が返却されませんでした。非公開・削除等の理由は未確認です。', default => 'API取得失敗。条件を確認して再試行してください。',
        };
    }

    public static function delta(Target $target): array
    {
        $i = app(Interval::class)->resolve($target);
        $label = match ($i['status']) {
            'latest_value_missing' => '未観測（数値欠損・増分未確認）',
            'latest_attempt_failed' => '今回の増分は未確認（観測停止・取得失敗）',
            default => $i['delta_views'] === null ? '未観測（初回または数値欠損）' : ($i['elapsed_seconds'] < 22 * 3600 ? '未観測（日次ではない参考区間） ' : '').($i['delta_views'] > 0 ? '+' : '').number_format($i['delta_views']),
        };
        if ($target->last_status === 'failed') {
            $label = '今回の増分は未確認（観測停止・取得失敗）';
        }

        return ['label' => $label, 'interval' => ($i['start_at'] ?? '').' → '.($i['end_at'] ?? '').'（'.round(($i['elapsed_seconds'] ?? 0) / 3600, 2).'時間）', 'note' => ($i['delta_views'] < 0 ? '負の差分：訂正等の可能性。' : '').($i['eligible'] ? '比較可能な約24時間区間。両端時刻を確認してください。' : '更新遅延／参考区間：'.$i['status'])];
    }

    public static function lens(string $lens): string
    {
        return match ($lens) {
            'recent_views' => '最近30日・再生数順', 'recent_new' => '最近30日・新着順', 'older_reference' => '180日以上前〜5年前・累計再生数順', default => '条件不明',
        };
    }

    public static function broadcast(string $status): string
    {
        return match ($status) {
            'none' => '通常動画', 'live' => 'ライブ配信中', 'upcoming' => '公開前・配信予約', default => '形態不明',
        };
    }

    public static function dailyDelta(Target $target): array
    {
        return self::delta($target);
    }
}
