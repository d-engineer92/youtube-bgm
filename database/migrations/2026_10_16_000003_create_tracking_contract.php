<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->boolean('active')->default(true);
            $t->uuid('cutover_operation')->nullable()->unique();
            $t->timestampTz('cutover_at')->nullable();
            $t->timestampTz('created_at');
        });
        DB::statement('CREATE UNIQUE INDEX datasets_one_active ON datasets (active) WHERE active');
        DB::table('datasets')->insert(['id' => (string) Str::uuid(), 'active' => true, 'created_at' => now()]);
        foreach (['targets', 'fetch_runs', 'explorations', 'comparisons'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignUuid('dataset_id')->nullable()->constrained('datasets')->restrictOnDelete();
            });
        }
        Schema::table('targets', function (Blueprint $t) {
            $t->string('ownership', 12)->default('unknown');
            $t->unique(['id', 'dataset_id']);
        });
        Schema::create('tracking_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('dataset_id')->constrained('datasets')->restrictOnDelete();
            $t->foreignId('target_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('state', 20)->default('active');
            $t->string('reason', 40)->nullable();
            $t->timestampTz('first_seen_at');
            $t->timestampTz('last_success_at')->nullable();
            $t->timestampTz('last_attempt_at')->nullable();
            $t->timestampTz('next_due_at')->index();
            $t->index(['dataset_id', 'state', 'next_due_at']);
        });
        Schema::create('tracking_slots', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('dataset_id')->constrained('datasets')->restrictOnDelete();
            $t->foreignId('target_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('scheduled_for');
            $t->string('state', 20)->default('pending');
            $t->string('reason', 40)->nullable();
            $t->timestampTz('fulfilled_at')->nullable();
            $t->unique(['dataset_id', 'target_id', 'scheduled_for']);
            $t->index(['target_id', 'scheduled_for', 'state']);
        });
        Schema::create('metadata_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('dataset_id')->constrained('datasets')->restrictOnDelete();
            $t->foreignId('target_id')->constrained()->cascadeOnDelete();
            $t->string('schema_version')->default('tracking-data-v1');
            $t->char('content_hash', 64);
            $t->jsonb('content');
            $t->timestampTz('fetched_at');
            $t->timestampTz('expires_at')->index();
            $t->index(['target_id', 'fetched_at']);
        });
        Schema::table('observations', function (Blueprint $t) {
            $t->dropForeign(['fetch_run_id']);
            $t->unsignedBigInteger('fetch_run_id')->nullable()->change();
            $t->foreign('fetch_run_id')->references('id')->on('fetch_runs')->nullOnDelete();
            $t->foreignUuid('dataset_id')->nullable()->constrained('datasets')->restrictOnDelete();
            $t->foreignId('metadata_version_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('tracking_slot_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->timestampTz('scheduled_for')->nullable();
            $t->bigInteger('like_count')->nullable();
            $t->bigInteger('comment_count')->nullable();
            $t->bigInteger('video_count')->nullable();
            $t->boolean('hidden_subscribers')->nullable();
            $t->jsonb('field_status')->default('{}');
            $t->string('origin', 20)->default('manual');
        });
        Schema::create('tracking_heartbeats', function (Blueprint $t) {
            $t->string('name')->primary();
            $t->timestampTz('seen_at');
        });
        Schema::table('comparisons', function (Blueprint $t) {
            $t->unsignedInteger('revision')->default(1);
            $t->timestampTz('as_of')->nullable();
            $t->timestampTz('evidence_expires_at')->nullable()->index();
            $t->jsonb('evidence')->nullable();
            $t->jsonb('analysis')->nullable();
            $t->unsignedInteger('analysis_revision')->default(0);
            $t->string('analysis_state', 12)->default('draft');
            $t->string('template_version')->default('guide-v1');
        });
        Schema::create('analysis_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('dataset_id')->constrained('datasets')->restrictOnDelete();
            $t->foreignId('comparison_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('revision');
            $t->string('state', 12);
            $t->string('origin', 12)->default('manual');
            $t->string('template_version');
            $t->jsonb('content')->nullable();
            $t->timestampTz('expires_at')->index();
            $t->timestampTz('created_at');
            $t->unique(['comparison_id', 'revision']);
        });
        Schema::table('comparison_target', fn (Blueprint $t) => $t->unsignedInteger('position')->default(0));
        Schema::create('production_briefs', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('dataset_id')->constrained('datasets')->restrictOnDelete();
            $t->foreignId('comparison_id')->constrained()->restrictOnDelete();
            $t->string('source', 8);
            $t->unsignedInteger('analysis_revision');
            $t->unsignedInteger('revision')->default(1);
            $t->jsonb('content');
            $t->jsonb('reflection')->nullable();
            $t->timestampsTz();
            $t->foreign(['comparison_id', 'analysis_revision'])->references(['comparison_id', 'revision'])->on('analysis_revisions')->restrictOnDelete();
        });
        foreach (['tracking_targets', 'tracking_slots', 'metadata_versions', 'observations'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->foreign(['target_id', 'dataset_id'], $table.'_target_dataset_fk')->references(['id', 'dataset_id'])->on('targets')->cascadeOnDelete();
            });
        }
        DB::statement("ALTER TABLE tracking_targets ADD CONSTRAINT tracking_state_allowed CHECK (state IN ('active','paused'))");
        DB::statement("ALTER TABLE tracking_slots ADD CONSTRAINT tracking_slot_state_allowed CHECK (state IN ('pending','fulfilled','failed','missed'))");
        DB::statement("ALTER TABLE targets ADD CONSTRAINT ownership_allowed CHECK (ownership IN ('own','competitor','unknown'))");

    }

    public function down(): void
    {
        throw new RuntimeException('Tracking migration is additive; rollback requires a separately reviewed migration.');
    }
};
