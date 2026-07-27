<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\MaintenanceModeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_is_available_by_default(): void
    {
        $this->getJson('/api/system/status')
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->get('/')->assertOk();
    }

    public function test_only_an_admin_with_password_and_confirmation_can_toggle_maintenance(): void
    {
        config(['offline.enabled' => true]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => 'password',
        ]);
        $assistant = User::factory()->create([
            'role' => 'assistant',
            'is_active' => true,
        ]);

        $this->actingAs($assistant)->putJson('/api/admin/maintenance', [
            'enabled' => true,
            'message' => 'Scheduled upgrade.',
            'current_password' => 'password',
            'confirmation' => 'START MAINTENANCE',
        ])->assertForbidden();

        $this->actingAs($admin)->putJson('/api/admin/maintenance', [
            'enabled' => true,
            'message' => 'Scheduled upgrade.',
            'current_password' => 'wrong-password',
            'confirmation' => 'START MAINTENANCE',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->actingAs($admin)->putJson('/api/admin/maintenance', [
            'enabled' => true,
            'message' => 'Scheduled upgrade.',
            'current_password' => 'password',
            'confirmation' => 'START MAINTENANCE',
        ])->assertOk()
            ->assertJsonPath('maintenance.enabled', true)
            ->assertJsonPath('maintenance.message', 'Scheduled upgrade.');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'site.maintenance_enabled',
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'event_type' => 'system.maintenance_updated',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->putJson('/api/admin/maintenance', [
            'enabled' => false,
            'message' => 'Scheduled upgrade.',
            'current_password' => 'password',
            'confirmation' => 'RESTORE WEBSITE',
        ])->assertOk()
            ->assertJsonPath('maintenance.enabled', false);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'site.maintenance_disabled',
        ]);
    }

    public function test_maintenance_blocks_the_public_and_non_admins_but_preserves_admin_recovery(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => 'password',
        ]);
        $customer = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
            'password' => 'password',
        ]);
        app(MaintenanceModeService::class)->update(true, 'Database upgrade in progress.', $admin);

        $this->get('/')
            ->assertStatus(503)
            ->assertSee('Currently under maintenance')
            ->assertHeader('X-Nenial-Maintenance', '1');
        $this->get('/login')->assertOk();
        $this->get('/face-terminal')->assertOk();
        $this->get('/auth/google/redirect')->assertStatus(503);
        $this->postJson('/api/auth/register', [
            'name' => 'Blocked Customer',
            'email' => 'blocked@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(503);
        $this->getJson('/api/storefront/products')
            ->assertStatus(503)
            ->assertJsonPath('maintenance', true)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->postJson('/api/auth/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertStatus(503)
            ->assertJsonPath('maintenance', true);

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $this->get('/')->assertOk();
        $this->getJson('/api/dashboard')->assertOk();
        $this->putJson('/api/admin/maintenance', [
            'enabled' => false,
            'message' => 'Database upgrade in progress.',
            'current_password' => 'password',
            'confirmation' => 'RESTORE WEBSITE',
        ])->assertOk()
            ->assertJsonPath('maintenance.enabled', false);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->get('/')->assertOk();
    }

    public function test_non_admin_sessions_and_disabled_admins_cannot_bypass_maintenance(): void
    {
        $activeAdmin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $disabledAdmin = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $assistant = User::factory()->create(['role' => 'assistant', 'is_active' => true]);
        app(MaintenanceModeService::class)->update(true, null, $activeAdmin);

        $this->actingAs($assistant)->getJson('/api/dashboard')
            ->assertStatus(503)
            ->assertJsonPath('maintenance', true);

        $this->actingAs($disabledAdmin)->getJson('/api/dashboard')
            ->assertStatus(503)
            ->assertJsonPath('maintenance', true);
    }

    public function test_machine_sync_and_registered_attendance_devices_remain_available(): void
    {
        config(['offline.sync_token' => 'maintenance-sync-secret']);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        app(MaintenanceModeService::class)->update(true, 'Maintenance.', $admin);

        $this->withToken('maintenance-sync-secret')
            ->getJson('/api/sync/configuration')
            ->assertOk()
            ->assertJsonPath('maintenance.enabled', true)
            ->assertJsonPath('capabilities.maintenance_sync', true);

        $token = Str::random(64);
        Device::create([
            'name' => 'Maintenance attendance terminal',
            'type' => 'facial',
            'token_hash' => hash('sha256', $token),
            'is_active' => true,
        ]);

        $this->withToken($token)->getJson('/api/device/employees')->assertOk();
    }

    public function test_remote_maintenance_updates_are_idempotent_and_cannot_overwrite_newer_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $maintenance = app(MaintenanceModeService::class);
        $current = $maintenance->update(true, 'Current maintenance window.', $admin);

        $maintenance->applyRemote($current);
        $this->assertDatabaseCount('audit_logs', 1);

        $older = [
            'enabled' => false,
            'message' => 'Stale restoration.',
            'started_at' => null,
            'updated_at' => CarbonImmutable::parse($current['updated_at'])->subMinute()->toIso8601String(),
        ];
        $this->assertTrue($maintenance->applyRemote($older)['enabled']);
        $this->assertDatabaseCount('audit_logs', 1);

        $newer = [
            'enabled' => false,
            'message' => 'Maintenance complete.',
            'started_at' => null,
            'updated_at' => CarbonImmutable::parse($current['updated_at'])->addMinute()->toIso8601String(),
        ];
        $this->assertFalse($maintenance->applyRemote($newer)['enabled']);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_sync_replication_requires_an_active_administrator_authorization(): void
    {
        config(['offline.sync_token' => 'maintenance-sync-secret']);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $assistant = User::factory()->create(['role' => 'assistant', 'is_active' => true]);
        $eventId = (string) Str::uuid();
        $payload = [
            'node_id' => 'store-main',
            'event_id' => $eventId,
            'payload' => [
                'enabled' => true,
                'message' => 'Authorized local maintenance.',
                'started_at' => now()->toIso8601String(),
                'updated_at' => now()->addMinute()->toIso8601String(),
                'authorized_by_email' => $assistant->email,
            ],
        ];

        $this->withToken('maintenance-sync-secret')
            ->postJson('/api/sync/maintenance', $payload)
            ->assertUnprocessable();

        $payload['payload']['authorized_by_email'] = $admin->email;
        $this->withToken('maintenance-sync-secret')
            ->postJson('/api/sync/maintenance', $payload)
            ->assertCreated()
            ->assertJsonPath('enabled', true);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'site.maintenance_enabled',
        ]);
        $this->assertDatabaseHas('sync_receipts', [
            'node_id' => 'store-main',
            'event_id' => $eventId,
            'event_type' => 'system.maintenance_updated',
        ]);
    }

    public function test_signed_payment_webhooks_remain_available_during_maintenance(): void
    {
        config([
            'services.paymongo.secret' => 'sk_test_example',
            'services.paymongo.webhook_secret' => 'paymongo-webhook-secret',
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        app(MaintenanceModeService::class)->update(true, 'Maintenance.', $admin);

        $body = json_encode([
            'data' => [
                'id' => 'evt_test_maintenance',
                'attributes' => ['type' => 'source.chargeable'],
            ],
        ], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'paymongo-webhook-secret');

        $this->call(
            'POST',
            '/api/payments/webhooks/paymongo',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature}",
            ],
            $body,
        )->assertNoContent();
    }
}
