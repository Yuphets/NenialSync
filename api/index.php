<?php

if (getenv('VERCEL')) {
    // The function physically lives in /api, but Laravel is mounted at the
    // domain root. Prevent Symfony from stripping /api from application URLs.
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';

    $storage = '/tmp/nenial-storage';
    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
        @mkdir($storage.'/'.$directory, 0775, true);
    }
    $_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;
}
require __DIR__.'/../public/index.php';
