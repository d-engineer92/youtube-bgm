<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Observation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['field_status' => 'array', 'scheduled_for' => 'immutable_datetime', 'fetched_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
