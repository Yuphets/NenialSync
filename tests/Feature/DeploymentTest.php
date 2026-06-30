<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentTest extends TestCase
{
    public function test_forwarded_https_is_used_for_vite_assets(): void
    {
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
