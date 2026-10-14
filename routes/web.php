<?php

use App\Http\Controllers\ResearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ResearchController::class, 'index'])->name('discovery');
Route::post('/search', [ResearchController::class, 'search'])->name('search');
Route::post('/register', [ResearchController::class, 'register'])->name('register');
Route::get('/runs/{id}', [ResearchController::class, 'run'])->name('runs.show');
Route::post('/runs/{id}/resume', [ResearchController::class, 'resume'])->name('runs.resume');
Route::post('/targets/{id}/watch', [ResearchController::class, 'watch'])->name('watch');
Route::post('/refresh-watch', [ResearchController::class, 'refresh'])->name('refresh');
Route::get('/compare', [ResearchController::class, 'compare'])->name('compare');
Route::post('/comparisons', [ResearchController::class, 'saveComparison'])->name('comparisons.store');
Route::get('/comparisons/{id}', [ResearchController::class, 'compare'])->name('comparisons.show');
