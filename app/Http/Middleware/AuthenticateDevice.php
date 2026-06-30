<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        abort_unless($plain, 401, 'Device token required.');
        $device = Device::where('token_hash', hash('sha256', $plain))->where('is_active', true)->first();
        abort_unless($device, 401, 'Invalid device token.');
        $device->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set('device', $device);

        return $next($request);
    }
}
