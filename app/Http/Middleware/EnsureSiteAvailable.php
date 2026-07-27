<?php

namespace App\Http\Middleware;

use App\Services\MaintenanceModeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSiteAvailable
{
    public function __construct(private readonly MaintenanceModeService $maintenance) {}

    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->maintenance->status();

        if (
            ! $status['enabled']
            || ($request->user()?->role === 'admin' && $request->user()->is_active)
            || $this->alwaysAvailable($request)
        ) {
            return $next($request);
        }

        if ($request->is('login')) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => $status['message'],
                'maintenance' => true,
                'status' => $status,
            ], 503, [
                'Cache-Control' => 'no-store, private',
                'Retry-After' => '300',
                'X-Nenial-Maintenance' => '1',
            ]);
        }

        return response()
            ->view('maintenance', ['maintenance' => $status], 503)
            ->header('Cache-Control', 'no-store, private')
            ->header('Retry-After', '300')
            ->header('X-Nenial-Maintenance', '1');
    }

    private function alwaysAvailable(Request $request): bool
    {
        return $request->is(
            'api/system/status',
            'api/auth/login',
            'api/auth/logout',
            'api/auth/capabilities',
            'api/sync/*',
            'api/device/*',
            'api/payments/webhooks/*',
            'face-terminal',
            'up',
        );
    }
}
