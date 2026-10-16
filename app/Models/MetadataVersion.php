<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetadataVersion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['content' => 'array', 'fetched_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
