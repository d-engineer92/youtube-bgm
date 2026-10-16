<?php

namespace App\Services\Tracking;

use App\Models\Target;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class Interval
{
    public function resolve(Target $target, ?CarbonInterface $asOf = null, bool $historical = false, ?iterable $projection = null, int $periodHours = 24): array
    {
        $asOf ??= now();
        $at = $asOf->timestamp;
        $now = now()->timestamp;
        // Normalize raw timestamps once. Repeated Carbon casts for every comparator made
        // 28,000-observation lists unnecessarily expensive; no calculation depends on timezone formatting.
        $points = [];
        $latest = null;
        foreach ($projection ?? $target->observations as $observation) {
            $raw = $observation instanceof Model ? $observation->getAttributes() : (array) $observation;
            $time = strtotime((string) $raw['fetched_at']);
            if (strtotime((string) $raw['expires_at']) <= $now || $time > $at) {
                continue;
            }
            $point = ['id' => $raw['id'], 'time' => $time, 'views' => $raw['view_count']];
            if (! $latest || [$time, $point['id']] > [$latest['time'], $latest['id']]) {
                $latest = $point;
            }
            if ($point['views'] !== null) {
                $points[] = $point;
            }
        }
        usort($points, fn ($a, $b) => [$b['time'], $b['id']] <=> [$a['time'], $a['id']]);
        $end = $points[0] ?? null;
        $start = null;
        if ($end) {
            $ideal = $end['time'] - $periodHours * 3600;
            foreach ($points as $point) {
                $seconds = $end['time'] - $point['time'];
                if ($seconds < ($periodHours + config('tracking.minimum_hours') - 24) * 3600 || $seconds > ($periodHours + config('tracking.maximum_hours') - 24) * 3600) {
                    continue;
                }
                if (! $start || [abs($point['time'] - $ideal), $point['time'], $point['id']] < [abs($start['time'] - $ideal), $start['time'], $start['id']]) {
                    $start = $point;
                }
            }
        }
        $reason = $end ? ($start ? 'comparable' : (count($points) === 1 ? 'first_observation' : 'interval_outside_window')) : 'no_valid_observation';
        $start ??= $points[1] ?? null;
        if ($start && $end && $start['time'] === $end['time']) {
            $start = null;
        }
        $late = false;
        $missing = false;
        $slots = $target->slots;
        $grace = config('tracking.schedule_grace_minutes') * 60;
        foreach ($slots as $slot) {
            $raw = $slot->getAttributes();
            $due = strtotime((string) $raw['scheduled_for']);
            $fulfilled = $raw['fulfilled_at'] ? strtotime((string) $raw['fulfilled_at']) : null;
            if ($due + $grace < $at && (! $fulfilled || $fulfilled > $at)) {
                $late = true;
            }
            if ($start && $end && $due > $start['time'] && $due <= $end['time'] && $raw['state'] === 'missed') {
                $missing = true;
            }
        }
        $tracking = $target->tracking;
        if ($tracking && strtotime((string) $tracking->getRawOriginal('next_due_at')) + $grace < $at) {
            $late = true;
        }
        if ($end && $at > $end['time'] + config('tracking.freshness_hours') * 3600) {
            $reason = 'stale_latest';
        } elseif ($late) {
            $reason = 'unfulfilled_due';
        } elseif ($missing) {
            $reason = 'missing_slot';
        }
        if ($latest && $latest['views'] === null) {
            $reason = 'latest_value_missing';
        }
        if (! $historical && $target->last_status === 'failed') {
            $reason = 'latest_attempt_failed';
        }
        if (! $historical && $tracking?->state === 'paused') {
            $reason = 'user_paused';
        }
        $seconds = $start && $end ? $end['time'] - $start['time'] : null;
        $delta = $start && $end ? $end['views'] - $start['views'] : null;
        $format = fn ($point) => $point ? gmdate('Y-m-d\TH:i:s\Z', $point['time']) : null;

        return ['period_hours' => $periodHours, 'resolver_version' => config('tracking.interval_version'), 'mode' => $historical ? 'fixed_past' : 'current', 'as_of' => gmdate('Y-m-d\TH:i:s\Z', $at), 'status' => $reason, 'eligible' => $reason === 'comparable', 'start_id' => $start['id'] ?? null, 'end_id' => $end['id'] ?? null, 'start_at' => $format($start), 'end_at' => $format($end), 'delta_views' => $delta, 'elapsed_seconds' => $seconds, 'growth_percent' => $start && $start['views'] > 0 ? 100 * $delta / $start['views'] : null, 'rate_reason' => ($start['views'] ?? null) === 0 ? 'zero_baseline' : null, 'latest_age_seconds' => $end ? $at - $end['time'] : null];
    }
}
