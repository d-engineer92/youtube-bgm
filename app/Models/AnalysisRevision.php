<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalysisRevision extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['content' => 'array', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
