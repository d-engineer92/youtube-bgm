<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingSlot extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_for' => 'immutable_datetime', 'fulfilled_at' => 'immutable_datetime'];
    }
}
