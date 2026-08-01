<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\User;
use App\Services\LocalSyncService;
use App\Services\SyncUserSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountAndBiometricSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_authenticated_account_is_logged_out_and_its_sessions_are_invalidated(): void
    {
        $user = User::factory()->create(['role' => 'user', 'is_active' => false]);
        $this->createSessionFor($user, 'disabled-session');

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is disabled. Contact an administrator if you need access restored.');

        $this->assertGuest();
        $this->assertDatabaseMissing('sessions', ['id' => 'disabled-session']);
    }

    public function test_account_sync_rejects_privilege_elevation_without_an_active_admin_authorizer(): void
    {
        config([
            'offline.sync_token' => 'sync-secret',
            'offline.privileged_secret' => 'privileged-secret',
            'offline.allowed_node_ids' => ['store-main'],
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $eventId = (string) Str::uuid();
        $payload = $this->signedUserPayload($customer, $eventId, ['role' => 'admin']);

        $this->withToken('sync-secret')->postJson('/api/sync/users', [
            'node_id' => 'store-main',
            'event_id' => $eventId,
            'payload' => $payload,
        ])->assertUnprocessable();

        $this->assertSame('user', $customer->fresh()->role);

        $authorizedEventId = (string) Str::uuid();
        $this->withToken('sync-secret')->postJson('/api/sync/users', [
            'node_id' => 'store-main',
            'event_id' => $authorizedEventId,
            'payload' => $this->signedUserPayload($customer, $authorizedEventId, [
                'role' => 'admin',
                'authorized_by_email' => $admin->email,
            ]),
        ])->assertCreated();

        $this->assertSame('admin', $customer->fresh()->role);
    }

    public function test_account_sync_rejects_untrusted_store_nodes_and_invalidates_sessions_on_disable(): void
    {
        config([
            'offline.sync_token' => 'sync-secret',
            'offline.privileged_secret' => 'privileged-secret',
            'offline.allowed_node_ids' => ['store-main'],
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $unknownEventId = (string) Str::uuid();
        $unknownPayload = $this->signedUserPayload($customer, $unknownEventId, [
            'is_active' => false,
            'authorized_by_email' => $admin->email,
        ], 'unknown-kiosk');

        $this->withToken('sync-secret')->postJson('/api/sync/users', [
            'node_id' => 'unknown-kiosk',
            'event_id' => $unknownEventId,
            'payload' => $unknownPayload,
        ])->assertForbidden();

        $this->createSessionFor($customer, 'synced-session');
        $storeEventId = (string) Str::uuid();
        $this->withToken('sync-secret')->postJson('/api/sync/users', [
            'node_id' => 'store-main',
            'event_id' => $storeEventId,
            'payload' => $this->signedUserPayload($customer, $storeEventId, [
                'is_active' => false,
                'authorized_by_email' => $admin->email,
            ]),
        ])->assertCreated();

        $this->assertFalse($customer->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'synced-session']);
    }

    public function test_stolen_sync_token_cannot_submit_missing_or_forged_account_signatures(): void
    {
        config([
            'offline.sync_token' => 'ordinary-sync-secret',
            'offline.privileged_secret' => 'separate-privileged-secret',
            'offline.allowed_node_ids' => ['store-main'],
        ]);
        $customer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $unsigned = $this->userPayload($customer, ['name' => 'Unauthorized Name']);

        $this->withToken('ordinary-sync-secret')->postJson('/api/sync/users', [
            'node_id' => 'store-main',
            'event_id' => (string) Str::uuid(),
            'payload' => $unsigned,
        ])->assertUnprocessable();

        $this->withToken('ordinary-sync-secret')->postJson('/api/sync/users', [
            'node_id' => 'store-main',
            'event_id' => (string) Str::uuid(),
            'payload' => [...$unsigned, 'sync_signature' => str_repeat('0', 64)],
        ])->assertForbidden()
            ->assertJsonPath('message', 'The account synchronization signature is invalid.');

        $this->assertNotSame('Unauthorized Name', $customer->fresh()->name);
    }

    public function test_account_signature_is_bound_to_the_event_and_stale_signed_updates_are_rejected(): void
    {
        config([
            'offline.sync_token' => 'sync-secret',
            'offline.privileged_secret' => 'privileged-secret',
            'offline.allowed_node_ids' => ['store-main'],
        ]);
        $customer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $originalEventId = (string) Str::uuid();
        $captured = $this->signedUserPayload($customer, $originalEventId, [
            'name' => 'First synchronized name',
        ]);

        $this->withToken('sync-secret')->postJson('/api/sync/users', [
            'node_id' => 'store-main',
            'event_id' => $originalEventId,
            'payload' => $captured,
        ])->assertCreated();

        $customer->refresh()->update(['name' => 'Newer cloud name']);
        $currentVersion = $customer->fresh()->sync_version;

        $replayEventId = (string) Str::uuid();
        $this->withToken('sync-secret')->postJson('/api/sync/users', [
            'node_id' => 'store-main',
            'event_id' => $replayEventId,
            'payload' => $captured,
        ])->assertForbidden();

        $staleEventId = (string) Str::uuid();
        $stale = collect($captured)->except('sync_signature')->all();
        $stale['sync_signature'] = app(SyncUserSignatureService::class)
            ->sign('store-main', $staleEventId, $stale);
        $this->withToken('sync-secret')->postJson('/api/sync/users', [
            'node_id' => 'store-main',
            'event_id' => $staleEventId,
            'payload' => $stale,
        ])->assertConflict()
            ->assertJsonPath(
                'message',
                'This account synchronization update is older than the current cloud account state.'
            );

        $this->assertSame('Newer cloud name', $customer->fresh()->name);
        $this->assertSame($currentVersion, $customer->fresh()->sync_version);
    }

    public function test_removing_an_employee_transactionally_removes_and_queues_face_enrollments(): void
    {
        config(['offline.enabled' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $employee = Employee::create([
            'employee_number' => 'EMP-SEC-001',
            'name' => 'Security Employee',
            'job_title' => 'Tester',
            'weekly_salary' => 5000,
            'incentive' => 0,
            'overtime_hourly_rate' => 100,
            'overtime_hours' => 0,
            'deduction_plan' => [],
            'face_subject_id' => 'FACE-SEC-001',
            'is_active' => true,
        ]);
        $device = Device::create([
            'name' => 'Security terminal',
            'type' => 'facial',
            'token_hash' => hash('sha256', 'terminal-token'),
            'is_active' => true,
        ]);
        $enrollment = FaceEnrollment::create([
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'subject_id' => $employee->face_subject_id,
            'employee_name' => $employee->name,
            'descriptors' => $this->descriptors(),
            'enrolled_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/employees/{$employee->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('employees', ['id' => $employee->id, 'is_active' => false]);
        $this->assertSoftDeleted('face_enrollments', ['id' => $enrollment->id, 'is_active' => false]);
        $this->assertDatabaseHas('sync_outbox', [
            'event_type' => 'employee.updated',
            'aggregate_id' => $employee->id,
        ]);
        $this->assertDatabaseHas('sync_outbox', [
            'event_type' => 'face.enrollment_updated',
            'aggregate_id' => $enrollment->id,
        ]);

        $this->withToken('terminal-token')
            ->getJson('/api/device/face-enrollments')
            ->assertOk()
            ->assertJsonMissing(['subject_id' => 'FACE-SEC-001']);
    }

    public function test_cloud_refresh_cannot_reactivate_face_data_for_an_inactive_employee(): void
    {
        config([
            'offline.enabled' => true,
            'offline.node_id' => 'store-main',
            'offline.cloud_url' => 'https://cloud.example',
            'offline.sync_token' => 'sync-secret',
            'offline.privileged_secret' => 'privileged-secret',
        ]);
        $user = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->createSessionFor($user, 'cloud-refresh-session');
        $employee = Employee::create([
            'employee_number' => 'EMP-CLOUD-001',
            'name' => 'Cloud Employee',
            'job_title' => 'Tester',
            'weekly_salary' => 5000,
            'incentive' => 0,
            'overtime_hourly_rate' => 100,
            'overtime_hours' => 0,
            'deduction_plan' => [],
            'face_subject_id' => 'FACE-CLOUD-001',
            'is_active' => true,
        ]);
        $enrollment = FaceEnrollment::create([
            'employee_id' => $employee->id,
            'subject_id' => $employee->face_subject_id,
            'employee_name' => $employee->name,
            'descriptors' => $this->descriptors(),
            'enrolled_at' => now(),
            'is_active' => true,
        ]);

        Http::fake([
            'https://cloud.example/api/sync/products' => Http::response([]),
            'https://cloud.example/api/sync/inventory-activity' => Http::response([]),
            'https://cloud.example/api/sync/configuration' => Http::response([
                'users' => [[...$this->userPayload($user), 'is_active' => false]],
                'employees' => [[
                    ...$employee->only([
                        'employee_number', 'name', 'job_title', 'weekly_salary', 'incentive',
                        'overtime_hourly_rate', 'overtime_hours', 'deduction_plan', 'face_subject_id',
                    ]),
                    'is_active' => false,
                    'deleted_at' => now()->toIso8601String(),
                ]],
                'devices' => [],
                'face_enrollments' => [[
                    'employee_number' => $employee->employee_number,
                    'subject_id' => $employee->face_subject_id,
                    'employee_name' => $employee->name,
                    'descriptors' => $this->descriptors(),
                    'is_active' => true,
                    'deleted_at' => null,
                ]],
            ]),
            'https://cloud.example/api/sync/orders' => Http::response([]),
            'https://cloud.example/api/sync/attendance' => Http::response([]),
            'https://cloud.example/api/sync/payroll-runs' => Http::response([]),
        ]);

        $result = app(LocalSyncService::class)->run();

        $this->assertTrue($result['accounts_synced']);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSoftDeleted('employees', ['id' => $employee->id, 'is_active' => false]);
        $this->assertSoftDeleted('face_enrollments', ['id' => $enrollment->id, 'is_active' => false]);
        $this->assertDatabaseMissing('sessions', ['id' => 'cloud-refresh-session']);
    }

    private function userPayload(User $user, array $overrides = []): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'password_hash' => $user->getRawOriginal('password'),
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'must_change_password' => (bool) $user->must_change_password,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'google_id' => $user->google_id,
            'avatar_url' => $user->avatar_url,
            'deleted_at' => $user->deleted_at?->toIso8601String(),
            'erased_identity_hash' => $user->erased_identity_hash,
            'lookup_email' => $user->email,
            'sync_version' => (int) $user->sync_version,
            ...$overrides,
        ];
    }

    private function signedUserPayload(
        User $user,
        string $eventId,
        array $overrides = [],
        string $nodeId = 'store-main',
    ): array {
        $payload = $this->userPayload($user, $overrides);
        $payload['sync_signature'] = app(SyncUserSignatureService::class)
            ->sign($nodeId, $eventId, $payload);

        return $payload;
    }

    private function createSessionFor(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }

    private function descriptors(): array
    {
        return array_fill(0, 3, array_fill(0, 128, 0.01));
    }
}
