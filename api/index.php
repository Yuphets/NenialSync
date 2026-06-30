<?php

if (getenv('VERCEL')) {
    $storage = '/tmp/nenial-storage';
    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
        @mkdir($storage.'/'.$directory, 0775, true);
    }
    $_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;
}
require __DIR__.'/../public/index.php';
