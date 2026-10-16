<?php

namespace App\Services\Tracking;

use App\Models\Comparison;
use App\Models\MetadataVersion;
use App\Models\Observation;
use App\Models\Target;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Review
{
    private function subject(Target $target, CarbonImmutable $asOf, int $periodHours, string $kind): array
    {
        $observations = $target->observations->filter(fn ($o) => $o->expires_at > now() && $o->fetched_at <= $asOf)->values();

        return ['subject_kind' => $kind, 'target_id' => $target->id, ($kind === 'video' ? 'video_id' : 'channel_id') => $target->external_id, 'first_seen_at' => $target->tracking?->first_seen_at?->toIso8601ZuluString(), 'tracking_state' => $target->tracking?->state, 'tracking_reason' => $target->tracking?->reason, 'next_due_at' => $target->tracking?->next_due_at?->toIso8601ZuluString(), 'ownership' => $target->ownership, 'interval' => app(Interval::class)->resolve($target, $asOf, false, null, $periodHours), 'observations' => $observations->map(fn ($o) => ['observation_id' => $o->id, 'metadata_version_id' => $o->metadata_version_id, 'scheduled_for' => $o->scheduled_for?->toIso8601ZuluString(), 'observed_at' => $o->fetched_at->toIso8601ZuluString(), 'view_count' => $o->view_count, 'like_count' => $o->like_count, 'comment_count' => $o->comment_count, 'subscriber_count' => $o->subscriber_count, 'video_count' => $o->video_count, 'hidden_subscribers' => $o->hidden_subscribers, 'origin' => $o->origin, 'field_status' => $o->field_status, 'expires_at' => $o->expires_at->toIso8601ZuluString()])->all()];
    }

    public function pack($targets, ?CarbonImmutable $asOf = null, int $periodHours = 24): array
    {
        $asOf ??= CarbonImmutable::now('UTC');
        $subjects = $targets->map(fn ($t) => $this->subject($t, $asOf, $periodHours, 'video'))->values()->all();
        $channelIds = $targets->pluck('video.channel_external_id')->filter()->unique();
        $channelTargets = Target::inMode()->where('kind', 'channel')->whereIn('external_id', $channelIds)->with(['observations', 'tracking', 'slots'])->get();
        $channels = $channelTargets->map(fn ($t) => $this->subject($t, $asOf, $periodHours, 'channel'))->all();
        foreach ($subjects as &$subject) {
            $target = $targets->firstWhere('id', $subject['target_id']);
            $subject['channel_id'] = $target?->video?->channel_external_id;
        }
        unset($subject);
        $all = collect(array_merge($subjects, $channels));
        $points = $all->flatMap(fn ($s) => $s['observations']);
        $expiry = now()->addDays(28);
        foreach ($points as $point) {
            $expiry = $expiry->min(CarbonImmutable::parse($point['expires_at']));
        }
        $versions = MetadataVersion::whereIn('id', $points->pluck('metadata_version_id')->filter()->unique())->where('expires_at', '>', now())->get();
        foreach ($versions as $v) {
            $expiry = $expiry->min($v->expires_at);
        }
        $pack = ['requested_period_hours' => $periodHours, 'schema_version' => 'tracking-review-v1', 'pack_id' => (string) Str::uuid(), 'dataset_id' => Dataset::id(), 'source' => config('youtube.mode'), 'as_of' => $asOf->toIso8601ZuluString(), 'resolver_version' => config('tracking.interval_version'), 'generated_at' => now()->toIso8601ZuluString(), 'expires_at' => $expiry->toIso8601ZuluString(), 'subjects' => $subjects, 'channels' => $channels, 'metadata_versions' => $versions->map(fn ($v) => ['id' => $v->id, 'target_id' => $v->target_id, 'content_hash' => $v->content_hash, 'content' => $v->content, 'fetched_at' => $v->fetched_at->toIso8601ZuluString(), 'expires_at' => $v->expires_at->toIso8601ZuluString()])->all(), 'limitations' => ['初回以前は未観測。区間長が近くても両端時刻は同一とは限りません。', 'チャンネル総再生数・公開動画数は全形式を含み、収集したBGMの合計や投稿数ではありません。', '画像証跡・AI用途・長期保存は無効。検索国は視聴者国ではありません。']];
        $pack['pack_hash'] = hash('sha256', json_encode($pack, JSON_THROW_ON_ERROR));

        return $pack;
    }

    public function save(array $ids, string $purpose, int $periodHours = 24): Comparison
    {
        $targets = Target::inMode()->where('kind', 'video')->whereIn('id', $ids)->with(['video', 'observations', 'tracking', 'slots'])->get()->sortBy(fn ($t) => array_search($t->id, $ids))->values();
        abort_unless($targets->count() === count($ids), 404);
        $pack = $this->pack($targets, null, $periodHours);
        foreach (['subjects', 'channels'] as $key) {
            foreach ($pack[$key] as &$subject) {
                $subject['interval']['mode'] = 'fixed_past';
            }unset($subject);
        }
        unset($pack['pack_hash']);
        $pack['pack_hash'] = hash('sha256', json_encode($pack, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($targets, $ids, $purpose, $pack) {
            $channelIds = $targets->pluck('video.channel_external_id')->filter();
            $type = $channelIds->count() !== $targets->count() ? 'unknown' : ($channelIds->unique()->count() === 1 ? 'same_channel' : 'cross_channel');
            $c = Comparison::create(['dataset_id' => Dataset::id(), 'source' => config('youtube.mode'), 'comparison_type' => $type, 'purpose' => $purpose, 'as_of' => $pack['as_of'], 'evidence_expires_at' => $pack['expires_at'], 'evidence' => $pack]);
            $c->targets()->sync(collect($ids)->mapWithKeys(fn ($id, $position) => [$id => ['position' => $position]])->all());

            return $c;
        });
    }

    public function assertEvidence(Comparison $comparison): array
    {
        Dataset::assert($comparison->dataset_id);
        abort_unless($comparison->evidence && $comparison->evidence_expires_at > now(), 410, '根拠失効。最新値は新しい比較として保存してください。');
        $pack = $comparison->evidence;
        $ids = collect(array_merge($pack['subjects'], $pack['channels'] ?? []))->flatMap(fn ($s) => array_column($s['observations'], 'observation_id'))->all();
        abort_unless(Observation::whereIn('id', $ids)->where('dataset_id', Dataset::id())->where('expires_at', '>', now())->count() === count($ids), 410, '根拠失効');

        return $pack;
    }
}
