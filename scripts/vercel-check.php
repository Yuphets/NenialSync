<?php

$errors = [];

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

if ($errors) {
    fwrite(STDERR, "Nenial deployment configuration failed:\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}

fwrite(STDOUT, "Nenial deployment environment is configured.\n");
