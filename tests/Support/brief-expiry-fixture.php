<?php

use App\Models\AnalysisRevision;
use App\Models\ProductionBrief;
use App\Models\Target;
use App\Services\Tracking\Review;
use App\Support\DatabasePolicy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

// Synthetic PostgreSQL only; never shorten evidence in the normal database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DatabasePolicy::assertTestConnection(DB::connection(), app()->environment());
if (config('youtube.mode') !== 'demo') {
    throw new RuntimeException('Demo only');
}
$c = app(Review::class)->save(Target::where('kind', 'video')->limit(2)->pluck('id')->all(), 'R2架空期限回帰');
$c->update(['evidence_expires_at' => now()->addSeconds(25), 'analysis_revision' => 1]);
$c->refresh();
AnalysisRevision::create(['id' => (string) Str::uuid(), 'dataset_id' => $c->dataset_id, 'comparison_id' => $c->id, 'revision' => 1, 'state' => 'draft', 'template_version' => $c->template_version, 'content' => ['hypothesis' => '期限付き架空仮説'], 'expires_at' => $c->evidence_expires_at, 'created_at' => now()]);
$b = ProductionBrief::create(['dataset_id' => $c->dataset_id, 'comparison_id' => $c->id, 'source' => 'demo', 'analysis_revision' => 1, 'content' => ['reference' => '保存済参考', 'avoid' => '保存済反例', 'one_change' => '保存済本人企画'], 'reflection' => ['result' => '保存済本人結果']]);
echo json_encode(['brief_id' => $b->id, 'expires_at' => $c->evidence_expires_at->toIso8601ZuluString()], JSON_THROW_ON_ERROR);
