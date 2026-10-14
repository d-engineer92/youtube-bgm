<?php

namespace App\Support;

use App\Models\Target;

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
            'success', 'complete' => '今回取得成功', 'failed' => '取得失敗', 'partial' => '一部成功・失敗あり', 'pending' => '未取得', 'running' => '処理中／中断時は再試行可', 'unavailable' => 'APIから返却なし（非公開・削除等の理由は未確認）', default => '未確認',
        };
    }

    public static function error(?string $reason): string
    {
        return match ($reason) {
            null => '', 'key_missing' => 'APIキー未設定。サーバーの.envに設定してください。実APIの取得は行われていません。',
            'daily_limit' => 'このアプリの日次呼出上限に達しました。', 'quota_exceeded' => 'YouTubeのクォータ上限。翌日の割当またはCloud Consoleを確認してください。',
            'network_error' => '通信失敗／タイムアウト。回数を制限して再試行しました。', 'temporary_error' => 'APIの一時エラー。回数を制限して再試行しました。',
            'operation_limit' => '今回の処理時間・呼出回数上限。未取得分は再試行できます。', 'access_denied' => 'APIへのアクセス拒否。キーの制限とAPIの有効化を確認してください。',
            'invalid_request' => 'APIが取得条件を受け付けませんでした。', 'channel_unavailable' => 'チャンネル情報／投稿一覧を取得できませんでした。',
            'expired_run' => '取得実行の保存期限切れ。新しく検索・登録してください。', 'busy' => '別の取得処理が動いています。完了後に再試行してください。',
            'not_returned' => 'APIから対象が返却されませんでした。非公開・削除等の理由は未確認です。', default => 'API取得失敗。条件を確認して再試行してください。',
        };
    }

    public static function delta(Target $target): array
    {
        if ($target->last_status !== 'success') {
            return ['label' => '今回の増分は未確認', 'interval' => '', 'note' => ''];
        }
        $points = $target->observations->filter(fn ($o) => $o->expires_at > now())->take(2)->values();
        if ($points->count() < 2) {
            return ['label' => '未観測（初回または前回データが失効）', 'interval' => '', 'note' => ''];
        }
        [$current, $previous] = [$points[0], $points[1]];
        if ($current->view_count === null || $previous->view_count === null) {
            return ['label' => '数値欠損・増分未確認', 'interval' => '', 'note' => ''];
        }
        $difference = $current->view_count - $previous->view_count;

        return ['label' => ($difference > 0 ? '+' : '').number_format($difference), 'interval' => self::date($previous->fetched_at).' → '.self::date($current->fetched_at).'（'.round($previous->fetched_at->diffInHours($current->fetched_at), 2).'時間）', 'note' => $difference < 0 ? '負の差分：訂正等の可能性。0へ補正していません。' : ''];
    }
}
