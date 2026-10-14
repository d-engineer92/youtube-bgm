<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ResearchMode
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->session()->get('research_mode') === 'demo') {
            config(['youtube.mode' => 'demo']);
        }

        return $next($request);
    }
}
