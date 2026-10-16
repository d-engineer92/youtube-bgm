<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comparison extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'analysis' => 'array', 'as_of' => 'immutable_datetime', 'evidence_expires_at' => 'immutable_datetime'];
    }

    public function targets()
    {
        return $this->belongsToMany(Target::class)->orderByPivot('position');
    }
}
