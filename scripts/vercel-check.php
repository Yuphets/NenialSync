<?php

$errors = [];

function env_value(string $key): string
{
    return trim((string) getenv($key));
}

function is_placeholder(string $value): bool
{
    $value = strtolower(trim($value));

    return $value === ''
        || str_starts_with($value, 'your-')
        || str_contains($value, 'example.com')
        || in_array($value, ['verified@email.com', 'username', 'password', 'secret', 'client-secret', 'client-id'], true);
}

if (! getenv('APP_KEY')) {
    $errors[] = 'APP_KEY is missing. Run `php artisan key:generate --show` locally and add the result to Vercel.';
}

$hasDatabaseUrl = getenv('DB_URL') || getenv('DATABASE_URL') || getenv('POSTGRES_URL');
$hasDatabaseParts = getenv('DB_CONNECTION') === 'pgsql'
    && getenv('DB_HOST')
    && getenv('DB_DATABASE')
    && getenv('DB_USERNAME')
    && getenv('DB_PASSWORD');

if (! $hasDatabaseUrl && ! $hasDatabaseParts) {
    $errors[] = 'Neon is not configured. Add DATABASE_URL (pooled connection) or the DB_* PostgreSQL variables to Vercel.';
}

if (strlen((string) getenv('SYNC_SHARED_SECRET')) < 32) {
    $errors[] = 'SYNC_SHARED_SECRET is missing or too short. Add a random secret of at least 32 characters to Vercel.';
}

$resendKey = env_value('RESEND_API_KEY');
$mailMailer = env_value('MAIL_MAILER') ?: 'log';
$mailFrom = env_value('MAIL_FROM_ADDRESS');

if ($resendKey) {
    if (is_placeholder($mailFrom)) {
        $errors[] = 'MAIL_FROM_ADDRESS must be a real verified sender address when RESEND_API_KEY is used.';
    }
} elseif ($mailMailer === 'smtp') {
    foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS'] as $key) {
        if (is_placeholder(env_value($key))) {
            $errors[] = "{$key} is missing or still uses a placeholder value. Add real SMTP credentials from your email provider.";
        }
    }

    if (in_array(strtolower(env_value('MAIL_HOST')), ['mailpit', 'localhost', '127.0.0.1'], true)) {
        $errors[] = 'MAIL_HOST is still pointing to a local mail server. Production needs a real SMTP host or RESEND_API_KEY.';
    }

    $mailScheme = strtolower(env_value('MAIL_SCHEME'));
    if ($mailScheme && ! in_array($mailScheme, ['smtp', 'smtps', 'tls', 'starttls', 'ssl'], true)) {
        $errors[] = 'MAIL_SCHEME must be smtp for port 587 or smtps for port 465. Legacy tls/starttls values are normalized by the app but smtp is recommended.';
    }
} else {
    $errors[] = 'Email delivery is not configured for customer OTP verification. Use real SMTP credentials or RESEND_API_KEY.';
}

$googleClientId = env_value('GOOGLE_CLIENT_ID');
$googleClientSecret = env_value('GOOGLE_CLIENT_SECRET');
$googleRedirect = env_value('GOOGLE_REDIRECT_URI');
if ($googleClientId || $googleClientSecret || $googleRedirect) {
    if (is_placeholder($googleClientId) || is_placeholder($googleClientSecret)) {
        $errors[] = 'Google OAuth is partially configured. GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET must be real Google Cloud OAuth Web Client values.';
    }

    if (! str_starts_with($googleRedirect, 'https://') || ! str_ends_with($googleRedirect, '/auth/google/callback')) {
        $errors[] = 'GOOGLE_REDIRECT_URI must be the exact HTTPS callback URL authorized in Google Cloud, e.g. https://nenialsync.vercel.app/auth/google/callback.';
    }
}

if ($errors) {
    fwrite(STDERR, "Nenial deployment configuration failed:\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}

fwrite(STDOUT, "Nenial deployment environment is configured.\n");
