<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $t) {
            $t->id();
            $t->string('source', 8);
            $t->string('kind', 8);
            $t->string('external_id', 64);
            $t->boolean('watched')->default(false);
            $t->text('purpose')->nullable();
            $t->string('last_status', 20)->default('pending');
            $t->timestampTz('last_attempt_at')->nullable();
            $t->timestampsTz();
            $t->unique(['source', 'kind', 'external_id']);
        });
        Schema::create('fetch_runs', function (Blueprint $t) {
            $t->id();
            $t->string('source', 8);
            $t->string('kind', 20);
            $t->string('run_key')->unique();
            $t->jsonb('conditions');
            $t->string('status', 20)->default('pending');
            $t->string('error_code', 40)->nullable();
            $t->text('next_page_token')->nullable();
            $t->boolean('listing_done')->default(false);
            $t->unsignedInteger('pages')->default(0);
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->timestampTz('expires_at')->index();
            $t->timestampsTz();
        });
        Schema::create('run_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fetch_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('target_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('pending');
            $t->string('error_code', 40)->nullable();
            $t->unique(['fetch_run_id', 'target_id']);
        });
        Schema::create('channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('target_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('title');
            $t->text('thumbnail_url')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->string('uploads_playlist', 64)->nullable();
            $t->bigInteger('view_count')->nullable();
            $t->bigInteger('subscriber_count')->nullable();
            $t->bigInteger('video_count')->nullable();
            $t->boolean('hidden_subscribers')->default(false);
            $t->timestampTz('fetched_at');
            $t->timestampTz('expires_at')->index();
        });
        Schema::create('videos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('target_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('channel_external_id', 64)->index();
            $t->text('title');
            $t->text('thumbnail_url')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->string('broadcast_status', 20)->default('unknown');
            $t->boolean('has_live_details')->default(false);
            $t->bigInteger('view_count')->nullable();
            $t->bigInteger('like_count')->nullable();
            $t->bigInteger('comment_count')->nullable();
            $t->timestampTz('fetched_at');
            $t->timestampTz('expires_at')->index();
        });
        Schema::create('observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('target_id')->constrained()->cascadeOnDelete();
            $t->foreignId('fetch_run_id')->constrained()->cascadeOnDelete();
            $t->bigInteger('view_count')->nullable();
            $t->bigInteger('subscriber_count')->nullable();
            $t->timestampTz('fetched_at');
            $t->timestampTz('expires_at')->index();
            $t->unique(['target_id', 'fetch_run_id']);
            $t->index(['target_id', 'fetched_at']);
        });
        Schema::create('api_days', function (Blueprint $t) {
            $t->id();
            $t->string('source', 8);
            $t->date('day');
            $t->unsignedInteger('search_calls')->default(0);
            $t->unsignedInteger('read_calls')->default(0);
            $t->boolean('blocked')->default(false);
            $t->unique(['source', 'day']);
        });
        Schema::create('comparisons', function (Blueprint $t) {
            $t->id();
            $t->string('source', 8);
            $t->string('comparison_type', 20);
            $t->text('purpose');
            $t->timestampsTz();
        });
        Schema::create('comparison_target', function (Blueprint $t) {
            $t->foreignId('comparison_id')->constrained()->cascadeOnDelete();
            $t->foreignId('target_id')->constrained()->cascadeOnDelete();
            $t->primary(['comparison_id', 'target_id']);
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        foreach (['sessions', 'comparison_target', 'comparisons', 'api_days', 'observations', 'videos', 'channels', 'run_items', 'fetch_runs', 'targets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
