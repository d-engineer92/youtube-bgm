<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fetched_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'hidden_subscribers' => 'boolean'];
    }

    public function target()
    {
        return $this->belongsTo(Target::class);
    }
}
