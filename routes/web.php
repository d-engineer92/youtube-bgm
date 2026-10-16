<?php

use App\Http\Controllers\ExplorationController;
use App\Http\Controllers\ResearchController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ResearchController::class, 'index'])->name('discovery');
Route::post('/search', [ResearchController::class, 'search'])->name('search');
Route::post('/register', [ResearchController::class, 'register'])->name('register');
Route::get('/runs/{id}', [ResearchController::class, 'run'])->name('runs.show');
Route::post('/runs/{id}/resume', [ResearchController::class, 'resume'])->name('runs.resume');
Route::post('/targets/{id}/watch', [ResearchController::class, 'watch'])->name('watch');
Route::post('/refresh-watch', [ResearchController::class, 'refresh'])->name('refresh');
Route::post('/watch-selected', [ResearchController::class, 'watchSelected'])->name('watch.selected');
Route::post('/research-mode', [ExplorationController::class, 'mode'])->name('research.mode');
Route::post('/explorations', [ExplorationController::class, 'start'])->name('explorations.start');
Route::get('/explorations/{id}', [ExplorationController::class, 'show'])->name('explorations.show');
Route::get('/explorations/{id}/progress', [ExplorationController::class, 'progress'])->name('explorations.progress');
Route::post('/explorations/{id}/stop', [ExplorationController::class, 'stop'])->name('explorations.stop');
Route::post('/explorations/{id}/resume', [ExplorationController::class, 'resume'])->name('explorations.resume');
Route::post('/explorations/{id}/tasks/{taskId}/deepen', [ExplorationController::class, 'deepen'])->name('explorations.deepen');
Route::post('/discovery-keywords/{id}', [ExplorationController::class, 'keyword'])->name('keywords.update');
Route::get('/compare', [ResearchController::class, 'compare'])->name('compare');
Route::post('/comparisons', [ResearchController::class, 'saveComparison'])->name('comparisons.store');
Route::get('/comparisons/{id}', [ResearchController::class, 'compare'])->name('comparisons.show');

Route::get('/tracking', [TrackingController::class, 'index'])->name('tracking');
Route::post('/tracking/{id}/state', [TrackingController::class, 'state'])->name('tracking.state');
Route::get('/comparisons/{id}/export', [TrackingController::class, 'export'])->name('tracking.export');
Route::post('/comparisons/{id}/analysis', [TrackingController::class, 'analysis'])->name('analysis.save');
Route::get('/briefs/new', [TrackingController::class, 'brief'])->name('briefs.new');
Route::get('/briefs/{id}', [TrackingController::class, 'brief'])->name('briefs.show');
Route::post('/briefs', [TrackingController::class, 'saveBrief'])->name('briefs.save');
Route::get('/policy', fn () => view('policy'))->name('policy');

Route::post('/comparisons/{id}/ai-result', fn () => abort(403, 'AI用途は条件未確認のため無効です。'));

Route::get('/selection', [TrackingController::class, 'selection'])->name('selection');
