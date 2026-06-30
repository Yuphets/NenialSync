<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:seed-if-empty', function () {
    if (User::query()->exists()) {
        $this->info('Existing application data detected; initial seeding skipped.');

        return;
    }

    $this->call('db:seed', ['--force' => true]);
})->purpose('Seed a newly migrated Nenial database without modifying an existing installation');
