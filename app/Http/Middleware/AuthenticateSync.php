<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSync
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('offline.sync_token');
        $provided = (string) $request->bearerToken();

        abort_unless($configured !== '' && $provided !== '' && hash_equals($configured, $provided), 401, 'Invalid synchronization token.');

        return $next($request);
    }
}
