<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TrackingTarget extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public const SLOW_RETRY_REASONS = ['not_returned', 'invalid_request', 'invalid_response', 'key_missing', 'access_denied', 'api_error'];

    public const DAILY_RETRY_REASONS = ['daily_limit', 'tracking_daily_limit', 'quota_exceeded'];

    public function scopeReady(Builder $query, string $dataset, string $source): void
    {
        $query->where('tracking_targets.dataset_id', $dataset)->where('tracking_targets.state', 'active')->where('tracking_targets.next_due_at', '<=', now())
            ->whereIn('tracking_targets.target_id', Target::where('source', $source)->select('id'))
            ->where(function ($q) {
                $q->whereNull('tracking_targets.last_attempt_at')
                    ->orWhere(fn ($q) => $q->whereIn('tracking_targets.reason', self::SLOW_RETRY_REASONS)->where('tracking_targets.last_attempt_at', '<=', now()->subDay()))
                    ->orWhere(fn ($q) => $q->whereIn('tracking_targets.reason', self::DAILY_RETRY_REASONS)->where('tracking_targets.last_attempt_at', '<', now('America/Los_Angeles')->startOfDay()->utc()))
                    ->orWhere(fn ($q) => $q->whereNotIn('tracking_targets.reason', array_merge(self::SLOW_RETRY_REASONS, self::DAILY_RETRY_REASONS))->where('tracking_targets.last_attempt_at', '<=', now()->subHour()))
                    ->orWhere(fn ($q) => $q->whereNull('tracking_targets.reason')->where('tracking_targets.last_attempt_at', '<=', now()->subMinutes(5)));
            });
    }

    public function retryAt(): ?CarbonImmutable
    {
        if (! $this->reason || ! $this->last_attempt_at) {
            return null;
        }
        if (in_array($this->reason, self::DAILY_RETRY_REASONS, true)) {
            return $this->last_attempt_at->timezone('America/Los_Angeles')->addDay()->startOfDay()->utc();
        }

        return in_array($this->reason, self::SLOW_RETRY_REASONS, true)
            ? $this->last_attempt_at->addDay() : $this->last_attempt_at->addHour();
    }

    protected function casts(): array
    {
        return ['first_seen_at' => 'immutable_datetime', 'last_success_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime', 'next_due_at' => 'immutable_datetime'];
    }
}
