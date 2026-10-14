<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comparison extends Model
{
    protected $guarded = [];

    public function targets()
    {
        return $this->belongsToMany(Target::class);
    }
}
