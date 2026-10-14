<?php

namespace App\Http\Middleware;

use App\Services\Retention;
use Closure;
use Illuminate\Http\Request;

class PruneExpiredData
{
    public function handle(Request $request, Closure $next)
    {
        app(Retention::class)->prune();
        $response = $next($request);
        // Do not create a second, persistent API-data cache in the browser or proxy.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
