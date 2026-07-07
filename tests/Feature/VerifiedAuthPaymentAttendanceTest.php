<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\EmailVerificationOtp;
use App\Models\Employee;
use App\Models\PasswordResetTicket;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class VerifiedAuthPaymentAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_unauthenticated_api_requests_return_json_401_instead_of_login_redirect_error(): void
    {
        $this->getJson('/api/local-sync/status')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_employee_can_only_time_in_once_per_philippine_day(): void
    {
        $token = Str::random(64);
        Device::create(['name' => 'Mobile scanner', 'type' => 'facial_mobile', 'token_hash' => hash('sha256', $token), 'is_active' => true]);
        $employee = Employee::whereNotNull('face_subject_id')->firstOrFail();

        $first = $this->withToken($token)->postJson('/api/device/attendance', [
            'subject_id' => $employee->face_subject_id, 'event_id' => (string) Str::uuid(),
            'recognized_at' => '2026-07-02T16:30:00Z', 'confidence' => 98,
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/device/attendance', [
            'subject_id' => $employee->face_subject_id, 'event_id' => (string) Str::uuid(),
            'recognized_at' => '2026-07-02T17:30:00Z', 'confidence' => 99,
        ])->assertOk()->assertJsonPath('already_recorded', true)->assertJsonPath('id', $first->json('id'));

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', ['employee_id' => $employee->id, 'attendance_date' => '2026-07-03']);
    }

    public function test_customer_registration_requires_the_emailed_otp_before_login(): void
    {
        Mail::fake();
        $email = 'verified.customer@example.com';
        $this->postJson('/api/auth/register', [
            'name' => 'Verified Customer', 'email' => $email,
            'password' => 'CustomerPass2026!', 'password_confirmation' => 'CustomerPass2026!',
        ])->assertCreated()->assertJsonPath('verification_required', true);

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'CustomerPass2026!'])
            ->assertForbidden()->assertJsonPath('verification_required', true);

        $user = User::where('email', $email)->firstOrFail();
        $code = '314159';
        EmailVerificationOtp::where('user_id', $user->id)->update([
            'code_hash' => hash_hmac('sha256', $code, (string) config('app.key')),
        ]);
        $this->postJson('/api/auth/verify-email', ['email' => $email, 'code' => $code])
            ->assertOk()->assertJsonPath('user.email', $email);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_login_throttle_does_not_block_a_different_account_on_the_same_ip(): void
    {
        RateLimiter::clear('login');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'first.account@example.com',
                'password' => 'DefinitelyWrong2026!',
            ])->assertUnprocessable()->assertJsonPath('message', 'Invalid credentials.');
        }

        $this->postJson('/api/auth/login', [
            'email' => 'another.account@example.com',
            'password' => 'DefinitelyWrong2026!',
        ])->assertUnprocessable()->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_login_throttle_is_scoped_to_the_account_and_client_ip_pair(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
                ->postJson('/api/auth/login', [
                    'email' => 'rate.limited@example.com',
                    'password' => 'DefinitelyWrong2026!',
                ])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->postJson('/api/auth/login', [
                'email' => 'rate.limited@example.com',
                'password' => 'DefinitelyWrong2026!',
            ])->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->postJson('/api/auth/login', [
                'email' => 'rate.limited@example.com',
                'password' => 'DefinitelyWrong2026!',
            ])->assertUnprocessable();
    }

    public function test_registration_cannot_replace_an_unverified_staff_account(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $originalPassword = $admin->password;
        $this->postJson('/api/auth/register', [
            'name' => 'Impostor', 'email' => $admin->email,
            'password' => 'Replacement2026!', 'password_confirmation' => 'Replacement2026!',
        ])->assertUnprocessable();

        $this->assertSame($originalPassword, $admin->fresh()->password);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_resolved_ticket_returns_the_temporary_password_to_its_requester(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $ticket = PasswordResetTicket::create([
            'ticket_number' => (string) Str::uuid(), 'user_id' => $user->id, 'email' => $user->email,
            'status' => 'resolved', 'temporary_password' => 'Temporary2026!', 'requested_at' => now(), 'resolved_at' => now(),
        ]);

        $this->postJson('/api/auth/password-ticket-status', ['email' => $user->email, 'ticket_number' => $ticket->ticket_number])
            ->assertOk()->assertJsonPath('temporary_password', 'Temporary2026!');
        $this->assertNotNull($ticket->fresh()->temporary_password_viewed_at);
    }

    public function test_customer_can_open_a_gcash_hosted_checkout_for_their_reserved_order(): void
    {
        Http::fake(['api.paymongo.com/*' => Http::response(['data' => [
            'id' => 'cs_test_nenial', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/session'],
        ]], 200)]);
        config(['services.paymongo.secret' => 'sk_test_example']);
        $customer = User::factory()->create(['role' => 'user', 'email_verified_at' => now()]);
        $product = Product::firstOrFail();
        $order = $this->actingAs($customer)->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'gcash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json();

        $this->actingAs($customer)->postJson("/api/orders/{$order['id']}/payment-checkout", ['provider' => 'gcash'])
            ->assertOk()->assertJsonPath('payment_url', 'https://checkout.paymongo.test/session');
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'payment_provider' => 'gcash', 'provider_session_id' => 'cs_test_nenial']);
    }

    public function test_new_employee_is_enrollable_and_removed_number_can_be_reactivated(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $payload = [
            'employee_number' => 'EMP-REUSE-1', 'name' => 'First Employee', 'job_title' => 'Loader',
            'weekly_salary' => 5000, 'incentive' => 0, 'overtime_hourly_rate' => 100,
            'overtime_hours' => 0, 'deduction_plan' => ['sss'], 'face_subject_id' => null,
        ];
        $employee = $this->actingAs($admin)->postJson('/api/employees', $payload)
            ->assertCreated()->assertJsonPath('employee_number', 'EMP-REUSE-1')->json();
        $this->assertNotEmpty($employee['face_subject_id']);

        $this->actingAs($admin)->deleteJson("/api/employees/{$employee['id']}")->assertNoContent();
        $this->actingAs($admin)->postJson('/api/employees', [...$payload, 'name' => 'Reactivated Employee'])
            ->assertOk()->assertJsonPath('id', $employee['id'])->assertJsonPath('name', 'Reactivated Employee');
        $this->assertDatabaseCount('employees', 7);
    }

    public function test_report_includes_a_payroll_run_that_overlaps_the_selected_period(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $this->actingAs($admin)->postJson('/api/payroll/runs', ['period_start' => '2026-06-27', 'period_end' => '2026-07-03'])->assertCreated();
        $this->actingAs($admin)->getJson('/api/reports?from=2026-07-01&to=2026-07-31')
            ->assertOk()->assertJsonCount(1, 'payroll.runs');
    }

    public function test_pos_additional_discount_is_applied_after_the_product_discount(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $product = Product::firstOrFail();
        $product->update(['price' => 100, 'discount_percent' => 10]);
        $this->actingAs($cashier)->postJson('/api/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method' => 'cash',
            'discount_percent' => 20, 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('discount_total', 28)->assertJsonPath('total', 72);
    }

    public function test_admin_can_cautiously_disable_and_restore_user_access(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $admin->update(['password' => 'AdminAccess2026!']);
        $customer = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($admin)->deleteJson("/api/users/{$customer->id}", [
            'current_password' => 'AdminAccess2026!', 'reason' => 'Customer requested temporary suspension.',
        ])->assertNoContent();
        $this->assertFalse($customer->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.access_disabled', 'auditable_id' => $customer->id]);

        $this->actingAs($admin)->putJson("/api/users/{$customer->id}/restore", [
            'current_password' => 'AdminAccess2026!', 'reason' => 'Identity was verified by the administrator.',
        ])->assertOk()->assertJsonPath('is_active', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.access_restored', 'auditable_id' => $customer->id]);
    }

    public function test_otp_resend_has_one_consistent_cooldown_and_resets_attempts(): void
    {
        Mail::fake();
        $email = 'otp.cooldown@example.com';
        $this->postJson('/api/auth/register', [
            'name' => 'OTP Customer', 'email' => $email,
            'password' => 'CustomerPass2026!', 'password_confirmation' => 'CustomerPass2026!',
        ])->assertCreated()->assertJsonPath('resend_after', 30);

        $this->postJson('/api/auth/resend-otp', ['email' => $email])
            ->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);
        $otp = EmailVerificationOtp::where('user_id', User::where('email', $email)->value('id'))->firstOrFail();
        $otp->update(['attempts' => 4, 'sent_at' => now()->subSeconds(31)]);
        $this->postJson('/api/auth/resend-otp', ['email' => $email])
            ->assertOk()->assertJsonPath('resend_after', 30);
        $this->assertSame(0, $otp->fresh()->attempts);
    }

    public function test_failed_otp_resend_does_not_replace_the_usable_code_or_start_a_new_cooldown(): void
    {
        Mail::fake();
        $email = 'otp.delivery.failure@example.com';
        $this->postJson('/api/auth/register', [
            'name' => 'OTP Delivery Customer', 'email' => $email,
            'password' => 'CustomerPass2026!', 'password_confirmation' => 'CustomerPass2026!',
        ])->assertCreated();

        $otp = EmailVerificationOtp::where('user_id', User::where('email', $email)->value('id'))->firstOrFail();
        $otp->update(['sent_at' => now()->subSeconds(31)]);
        $original = $otp->fresh()->only(['code_hash', 'attempts', 'expires_at', 'sent_at']);

        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP unavailable'));
        $this->postJson('/api/auth/resend-otp', ['email' => $email])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Verification email could not be delivered. Please check the address and try again shortly.');

        $this->assertSame($original, $otp->fresh()->only(['code_hash', 'attempts', 'expires_at', 'sent_at']));
    }

    public function test_admin_can_permanently_erase_a_disabled_account(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $admin->update(['password' => 'AdminErase2026!']);
        $customer = User::factory()->create(['role' => 'user', 'is_active' => false, 'google_id' => 'google-private-id', 'avatar_url' => 'https://example.test/private.jpg']);
        $originalEmail = $customer->email;

        $this->actingAs($admin)->deleteJson("/api/users/{$customer->id}/erase", [
            'current_password' => 'AdminErase2026!', 'email_confirmation' => $originalEmail,
            'confirmation_phrase' => 'PERMANENTLY ERASE', 'reason' => 'Verified customer erasure request received by support.',
        ])->assertNoContent();

        $erased = User::withTrashed()->findOrFail($customer->id);
        $this->assertTrue($erased->trashed());
        $this->assertNotSame($originalEmail, $erased->email);
        $this->assertNull($erased->google_id);
        $this->assertSame(hash('sha256', strtolower($originalEmail)), $erased->erased_identity_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.account_permanently_erased', 'auditable_id' => $customer->id]);
    }

    public function test_admin_can_download_a_sanitized_company_backup(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $admin->update(['password' => 'AdminBackup2026!']);
        $response = $this->actingAs($admin)->postJson('/api/admin/backup', ['current_password' => 'AdminBackup2026!'])->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('nenial-company-backup', $content);
        $this->assertStringContainsString('inventory_movements', $content);
        $this->assertStringNotContainsString($admin->getRawOriginal('password'), $content);
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.backup_downloaded', 'actor_id' => $admin->id]);
    }
}
