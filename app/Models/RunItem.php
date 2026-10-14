<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RunItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function target()
    {
        return $this->belongsTo(Target::class);
    }
}
