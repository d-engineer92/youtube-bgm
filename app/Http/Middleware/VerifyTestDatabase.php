<?php

namespace App\Http\Middleware;

use App\Support\DatabasePolicy;
use Closure;
use Illuminate\Http\Request;

class VerifyTestDatabase
{
    public function handle(Request $request, Closure $next)
    {
        // Precede session middleware and all controllers for the real test server.
        $browserTest = app()->environment('testing') && ! app()->runningInConsole();
        if ($browserTest) {
            DatabasePolicy::assertTestConnection(app('db')->connection(), app()->environment());
        }
        $response = $next($request);
        if ($browserTest) {
            $response->headers->set('X-BGM-Test-Database', 'pgsql:'.DatabasePolicy::TEST_DATABASE);
        }

        return $response;
    }
}
