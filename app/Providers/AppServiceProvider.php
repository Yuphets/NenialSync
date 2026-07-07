<?php

namespace App\Providers;

use App\Database\NeonPostgresConnector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('db.connector.pgsql', fn () => new NeonPostgresConnector);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureAuthRateLimiters();
    }

    private function configureAuthRateLimiters(): void
    {
        RateLimiter::for('auth-login', function (Request $request) {
            $email = $this->rateLimitEmail($request);
            $ip = $request->ip() ?: 'unknown';

            return [
                $this->jsonLimit(
                    Limit::perMinute(10)->by("auth-login:{$email}|{$ip}"),
                    'Too many login attempts for this account. Please wait :seconds seconds before trying again.'
                ),
                $this->jsonLimit(
                    Limit::perMinute(120)->by("auth-login-network:{$ip}"),
                    'Too many login attempts from this network. Please wait :seconds seconds before trying again.'
                ),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request) {
            $email = $this->rateLimitEmail($request);
            $ip = $request->ip() ?: 'unknown';

            return [
                $this->jsonLimit(
                    Limit::perMinute(5)->by("auth-register:{$email}|{$ip}"),
                    'Too many registration attempts for this email. Please wait :seconds seconds before trying again.'
                ),
                $this->jsonLimit(
                    Limit::perMinute(60)->by("auth-register-network:{$ip}"),
                    'Too many registration attempts from this network. Please wait :seconds seconds before trying again.'
                ),
            ];
        });

        RateLimiter::for('auth-otp-verify', function (Request $request) {
            $email = $this->rateLimitEmail($request);
            $ip = $request->ip() ?: 'unknown';

            return [
                $this->jsonLimit(
                    Limit::perMinute(10)->by("auth-otp-verify:{$email}|{$ip}"),
                    'Too many verification attempts for this email. Please wait :seconds seconds before trying again.'
                ),
            ];
        });

        RateLimiter::for('auth-otp-resend', function (Request $request) {
            $email = $this->rateLimitEmail($request);
            $ip = $request->ip() ?: 'unknown';

            return [
                $this->jsonLimit(
                    Limit::perMinute(10)->by("auth-otp-resend:{$email}|{$ip}"),
                    'Too many code requests for this email. Please wait :seconds seconds before trying again.'
                ),
                $this->jsonLimit(
                    Limit::perMinute(80)->by("auth-otp-resend-network:{$ip}"),
                    'Too many code requests from this network. Please wait :seconds seconds before trying again.'
                ),
            ];
        });
    }

    private function rateLimitEmail(Request $request): string
    {
        $email = Str::lower(trim((string) $request->input('email', 'guest')));
        $safe = preg_replace('/[^a-z0-9@._+-]/', '_', $email) ?: 'guest';

        return substr($safe, 0, 180);
    }

    private function jsonLimit(Limit $limit, string $message): Limit
    {
        return $limit->response(function (Request $request, array $headers) use ($message) {
            $retryAfter = (int) ($headers['Retry-After'] ?? 60);

            return response()->json([
                'message' => str_replace(':seconds', (string) $retryAfter, $message),
                'retry_after' => $retryAfter,
            ], 429, $headers);
        });
    }
}
