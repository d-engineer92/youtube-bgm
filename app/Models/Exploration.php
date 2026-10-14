<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exploration extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['t0' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'heartbeat_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'stop_requested' => 'boolean', 'in_flight' => 'boolean', 'consumption_uncertain' => 'boolean'];
    }

    public function tasks()
    {
        return $this->hasMany(ExplorationTask::class)->orderBy('position');
    }
}
