<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreserveSessionFlash
{
    /**
     * Keep existing session flash data intact and prevent background GET endpoints
     * from overwriting the session's previous URL.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } finally {
            if ($request->hasSession()) {
                $request->session()->reflash();
            }
        }

        if ($request->isMethod('GET')) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        return $response;
    }
}
