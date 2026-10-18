<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_days', function (Blueprint $table) {
            $table->unsignedInteger('tracking_read_calls')->default(0);
        });
        // Historical calls cannot be attributed reliably. Do not grant a fresh budget.
        DB::table('api_days')->update(['tracking_read_calls' => DB::raw('read_calls')]);
    }

    public function down(): void
    {
        Schema::table('api_days', fn (Blueprint $table) => $table->dropColumn('tracking_read_calls'));
    }
};
