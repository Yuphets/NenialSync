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
use Illuminate\Support\Str;
use Tests\TestCase;

class VerifiedAuthPaymentAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
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
}
