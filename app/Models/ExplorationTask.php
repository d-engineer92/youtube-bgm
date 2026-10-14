<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExplorationTask extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'lease_until' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function fetchRun()
    {
        return $this->belongsTo(FetchRun::class);
    }

    public function exploration()
    {
        return $this->belongsTo(Exploration::class);
    }
}
