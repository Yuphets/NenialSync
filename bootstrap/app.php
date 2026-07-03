<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\AuthenticateSync;
use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\AuthenticationException;
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
        // Vercel terminates TLS before forwarding requests to the PHP function.
        // Trust its forwarding headers so generated Vite asset URLs remain HTTPS.
        $middleware->trustProxies(at: '*');
        $middleware->alias(['role' => EnsureRole::class, 'device' => AuthenticateDevice::class, 'sync' => AuthenticateSync::class]);
        $middleware->validateCsrfTokens(except: ['api/device/*', 'api/sync/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect('/login');
        });
    })->create();
