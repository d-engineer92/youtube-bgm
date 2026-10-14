<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('explorations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('source', 8);
            $t->uuid('idempotency_key');
            $t->uuid('parent_exploration_id')->nullable();
            $t->string('preset_id', 10);
            $t->string('catalog_version');
            $t->string('catalog_hash', 64);
            $t->timestampTz('t0');
            $t->string('status', 20)->default('queued');
            $t->string('stop_reason', 40)->nullable();
            $t->boolean('stop_requested')->default(false);
            $t->unsignedInteger('search_calls')->default(0);
            $t->unsignedInteger('read_calls')->default(0);
            $t->unsignedInteger('search_limit');
            $t->unsignedInteger('read_limit');
            $t->unsignedInteger('busy_attempts')->default(0);
            $t->boolean('in_flight')->default(false);
            $t->boolean('consumption_uncertain')->default(false);
            $t->timestampTz('heartbeat_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampTz('expires_at')->index();
            $t->timestampsTz();
            $t->unique(['source', 'idempotency_key']);
        });
        Schema::create('exploration_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('exploration_id')->constrained()->cascadeOnDelete();
            $t->string('task_key');
            $t->string('theme_id');
            $t->string('label_ja');
            $t->text('purpose_ja');
            $t->text('meaning_ja');
            $t->string('origin');
            $t->string('lens', 30);
            $t->jsonb('conditions');
            $t->unsignedInteger('position');
            $t->string('status', 20)->default('pending');
            $t->string('error_code', 40)->nullable();
            $t->foreignId('fetch_run_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->uuid('claim')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('expires_at')->index();
            $t->unique(['exploration_id', 'task_key']);
        });
        Schema::create('exploration_keywords', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('source', 8);
            $t->string('preset_id', 10);
            $t->string('parent_theme_id')->nullable();
            $t->string('label_ja');
            $t->text('query');
            $t->text('normalized_query');
            $t->text('meaning_ja');
            $t->text('reason_ja');
            $t->string('source_kind', 30);
            $t->string('prompt_id');
            $t->string('schema_version');
            $t->string('fingerprint', 64);
            $t->jsonb('input_context');
            $t->string('status', 20)->default('proposed');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['source', 'preset_id', 'normalized_query']);
        });
        Schema::table('run_items', function (Blueprint $t) {
            $t->unsignedInteger('search_rank')->nullable();
        });
        Schema::table('fetch_runs', function (Blueprint $t) {
            $t->unsignedInteger('search_hits')->default(0);
            $t->string('response_region', 10)->nullable();
            $t->timestampTz('listed_at')->nullable();
        });
        Schema::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['failed_jobs', 'jobs', 'exploration_keywords', 'exploration_tasks', 'explorations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('run_items', fn (Blueprint $t) => $t->dropColumn('search_rank'));
        Schema::table('fetch_runs', fn (Blueprint $t) => $t->dropColumn(['search_hits', 'response_region', 'listed_at']));
    }
};
