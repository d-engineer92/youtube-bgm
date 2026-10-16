<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Target extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['watched' => 'boolean', 'last_attempt_at' => 'immutable_datetime'];
    }

    public function scopeInMode(Builder $q): Builder
    {
        return $q->where('source', config('youtube.mode'));
    }

    public function tracking()
    {
        return $this->hasOne(TrackingTarget::class);
    }

    public function slots()
    {
        return $this->hasMany(TrackingSlot::class);
    }

    public function video()
    {
        return $this->hasOne(Video::class);
    }

    public function channel()
    {
        return $this->hasOne(Channel::class);
    }

    public function observations()
    {
        return $this->hasMany(Observation::class)->orderByDesc('fetched_at')->orderByDesc('id');
    }
}
