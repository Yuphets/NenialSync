<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxPasswordAndTerminalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_pos_sale_records_vat_inclusive_breakdown(): void
    {
        $cashier = User::where('role', 'cashier')->first();
        $product = Product::first();
        $response = $this->actingAs($cashier)->postJson('/api/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $total = (float) $response->json('total');
        $vatable = (float) $response->json('vatable_sales');
        $vat = (float) $response->json('vat_amount');
        $this->assertEqualsWithDelta($total, $vatable + $vat, 0.01);
        $this->assertEqualsWithDelta($total * 0.12 / 1.12, $vat, 0.01);
    }

    public function test_admin_resolves_password_ticket_with_temporary_password(): void
    {
        $admin = User::where('role', 'admin')->first();
        $admin->update(['password' => 'AdminCurrent2026!']);
        $customer = User::where('role', 'user')->first();
        $ticket = $this->postJson('/api/auth/password-tickets', ['email' => $customer->email, 'reason' => 'Forgot password'])
            ->assertAccepted()->json('ticket_number');
        $ticketId = \App\Models\PasswordResetTicket::where('ticket_number', $ticket)->value('id');

        $this->actingAs($admin)->postJson("/api/users/{$customer->id}/password-reset", [
            'ticket_id' => $ticketId, 'current_password' => 'AdminCurrent2026!',
            'password' => 'TemporaryUser2026!', 'password_confirmation' => 'TemporaryUser2026!',
        ])->assertOk();

        $this->assertTrue(Hash::check('TemporaryUser2026!', $customer->fresh()->password));
        $this->assertTrue($customer->fresh()->must_change_password);
        $this->assertDatabaseHas('password_reset_tickets', ['id' => $ticketId, 'status' => 'resolved', 'resolved_by' => $admin->id]);
    }

    public function test_facial_terminal_token_can_list_only_enrollable_employees(): void
    {
        $token = Str::random(64);
        Device::create(['name' => 'Kiosk', 'type' => 'facial', 'token_hash' => hash('sha256', $token), 'is_active' => true]);
        Employee::first()->update(['face_subject_id' => null]);

        $this->withToken($token)->getJson('/api/device/employees')->assertOk()
            ->assertJsonMissing(['face_subject_id' => null]);
    }

    public function test_admin_and_assistant_can_download_payroll_csv(): void
    {
        $assistant = User::where('role', 'assistant')->first();
        $this->actingAs($assistant)->get('/api/payroll/export?period_start=2026-06-25&period_end=2026-07-01')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_sync_configuration_contains_accounts_workforce_and_devices(): void
    {
        config(['offline.sync_token' => 'test-sync-secret']);
        $this->withToken('test-sync-secret')->getJson('/api/sync/configuration')->assertOk()
            ->assertJsonStructure(['users' => [['email', 'password_hash', 'role']], 'employees', 'devices']);
    }
}
