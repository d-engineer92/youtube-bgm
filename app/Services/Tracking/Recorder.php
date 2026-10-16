<?php

namespace App\Services\Tracking;

use App\Models\FetchRun;
use App\Models\MetadataVersion;
use App\Models\Observation;
use App\Models\Target;
use App\Models\TrackingTarget;
use App\Services\YouTube\ApiFailure;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class Recorder
{
    // Called inside Collector's transaction: current value, immutable evidence and tracking commit together.
    public function record(FetchRun $run, Target $target, array $data, array $base, CarbonInterface $time, CarbonInterface $expires): void
    {
        Dataset::assert($run->dataset_id);
        $target = Target::lockForUpdate()->findOrFail($target->id);
        if ($target->dataset_id && $target->dataset_id !== $run->dataset_id) {
            throw new ApiFailure('old_generation');
        }
        $target->update(['dataset_id' => $run->dataset_id]);
        $snippet = $data['snippet'] ?? [];
        $content = $target->kind === 'video'
            ? array_intersect_key($snippet, array_flip(['title', 'description', 'tags', 'publishedAt', 'liveBroadcastContent', 'defaultLanguage', 'defaultAudioLanguage', 'categoryId', 'thumbnails'])) + ['duration' => $data['contentDetails']['duration'] ?? null]
            : ['title' => $snippet['title'] ?? null, 'publishedAt' => $snippet['publishedAt'] ?? null, 'uploads' => $base['uploads_playlist'] ?? null];
        $keys = $target->kind === 'video' ? ['title', 'description', 'tags', 'publishedAt', 'liveBroadcastContent', 'defaultLanguage', 'defaultAudioLanguage', 'categoryId', 'thumbnails', 'duration'] : ['title', 'publishedAt', 'uploads'];
        $content = array_replace(array_fill_keys($keys, null), $content);
        $content['field_status'] = array_map(fn ($value) => $value === null ? 'not_returned' : 'returned', $content);
        ksort($content);
        $version = MetadataVersion::create(['dataset_id' => $run->dataset_id, 'target_id' => $target->id, 'content_hash' => hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)), 'content' => $content, 'fetched_at' => $time, 'expires_at' => $expires]);
        $tracking = TrackingTarget::firstOrCreate(['target_id' => $target->id], ['dataset_id' => $run->dataset_id, 'first_seen_at' => $time, 'last_success_at' => $time, 'next_due_at' => $time->copy()->addDay()]);
        $slotId = null;
        $scheduled = null;
        if ($tracking->next_due_at <= $time) {
            $scheduled = $tracking->next_due_at;
            while ($scheduled->addDay() <= $time) {
                DB::table('tracking_slots')->upsert([['dataset_id' => $run->dataset_id, 'target_id' => $target->id, 'scheduled_for' => $scheduled, 'state' => 'missed', 'reason' => 'downtime']], ['dataset_id', 'target_id', 'scheduled_for'], ['state', 'reason']);
                $scheduled = $scheduled->addDay();
            }
            DB::table('tracking_slots')->upsert([['dataset_id' => $run->dataset_id, 'target_id' => $target->id, 'scheduled_for' => $scheduled, 'state' => 'fulfilled', 'fulfilled_at' => $time, 'reason' => null]], ['dataset_id', 'target_id', 'scheduled_for'], ['state', 'fulfilled_at', 'reason']);
            $slotId = DB::table('tracking_slots')->where('target_id', $target->id)->where('scheduled_for', $scheduled)->value('id');
            $tracking->next_due_at = $scheduled->addDay();
        }
        $values = array_intersect_key($base, array_flip(['view_count', 'like_count', 'comment_count', 'subscriber_count', 'video_count', 'hidden_subscribers']));
        $status = [];
        foreach (['view_count', 'like_count', 'comment_count', 'subscriber_count', 'video_count'] as $field) {
            $status[$field] = isset($values[$field]) ? 'returned' : ($field === 'subscriber_count' && ($base['hidden_subscribers'] ?? false) ? 'hidden' : 'not_returned');
        }
        Observation::create($values + ['dataset_id' => $run->dataset_id, 'target_id' => $target->id, 'fetch_run_id' => $run->id, 'metadata_version_id' => $version->id, 'tracking_slot_id' => $slotId, 'scheduled_for' => $scheduled, 'field_status' => $status, 'origin' => $run->kind === 'tracking' ? 'scheduled' : 'manual', 'fetched_at' => $time, 'expires_at' => $expires]);
        $tracking->last_success_at = $time;
        $tracking->last_attempt_at = $time;
        $tracking->reason = null;
        $tracking->save();
    }
}
