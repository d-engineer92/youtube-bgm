<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FetchRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'listing_done' => 'boolean', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function scopeInMode(Builder $q): Builder
    {
        return $q->where('source', config('youtube.mode'));
    }

    public function items()
    {
        return $this->hasMany(RunItem::class);
    }
}
