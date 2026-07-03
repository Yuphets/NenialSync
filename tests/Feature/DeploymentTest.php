<?php

namespace Tests\Feature;

use Illuminate\Foundation\Vite;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    public function test_vercel_serves_mobile_css_and_face_models_as_static_assets(): void
    {
        $config = json_decode(file_get_contents(base_path('vercel.json')), true, flags: JSON_THROW_ON_ERROR);
        $routes = collect($config['routes'])->keyBy('src');

        $this->assertSame('/public/responsive.css', $routes['/responsive.css']['dest']);
        $this->assertSame('/public/face-models/$1', $routes['/face-models/(.*)']['dest']);
        $this->assertSame('/public/face-manifest.webmanifest', $routes['/face-manifest.webmanifest']['dest']);
    }

    public function test_forwarded_https_is_used_for_vite_assets(): void
    {
        app(Vite::class)->useHotFile(storage_path('framework/testing/nonexistent-vite-hot-file'));
        $response = $this->withHeaders([
            'Host' => 'nenialsync.vercel.app',
            'X-Forwarded-Host' => 'nenialsync.vercel.app',
            'X-Forwarded-Proto' => 'https',
        ])->get('/');

        $response->assertOk();
        $response->assertSee('https://nenialsync.vercel.app/build/assets/', false);
        $response->assertDontSee('http://nenialsync.vercel.app/build/assets/', false);
    }
}
