<?php

use App\Http\Middleware\PruneExpiredData;
use App\Http\Middleware\VerifyTestDatabase;
use App\Services\YouTube\ApiFailure;
use App\Support\Presentation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(VerifyTestDatabase::class);
        $middleware->web(append: [PruneExpiredData::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiFailure::class]);
        $exceptions->render(function (ApiFailure $e, Request $request) {
            return response(Presentation::error($e->reason), 503);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
