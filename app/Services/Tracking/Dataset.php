<?php

namespace App\Services\Tracking;

use App\Services\YouTube\ApiFailure;
use Illuminate\Support\Facades\DB;

class Dataset
{
    public static function id(): string
    {
        return DB::table('datasets')->where('active', true)->sole()->id;
    }

    public static function assert(?string $id): void
    {
        if ($id !== self::id()) {
            throw new ApiFailure('old_generation');
        }
    }

    public static function heartbeat(string $name): void
    {
        DB::table('tracking_heartbeats')->updateOrInsert(['name' => $name], ['seen_at' => now()]);
    }
}
