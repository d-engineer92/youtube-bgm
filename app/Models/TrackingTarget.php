<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingTarget extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['first_seen_at' => 'immutable_datetime', 'last_success_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime', 'next_due_at' => 'immutable_datetime'];
    }
}
