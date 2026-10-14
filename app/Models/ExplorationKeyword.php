<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExplorationKeyword extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['input_context' => 'array', 'approved_at' => 'immutable_datetime'];
    }
}
