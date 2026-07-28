<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Employee;
use App\Models\PasswordResetTicket;
use App\Models\PayrollRun;
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
            'payment_method' => 'cash',
            'amount_tendered' => 10000,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $total = (float) $response->json('total');
        $vatable = (float) $response->json('vatable_sales');
        $vat = (float) $response->json('vat_amount');
        $this->assertEqualsWithDelta($total, $vatable + $vat, 0.01);
        $this->assertEqualsWithDelta($total * 0.12 / 1.12, $vat, 0.01);
        $this->assertEqualsWithDelta(10000 - $total, (float) $response->json('change_due'), 0.01);
        $response->assertJsonPath('amount_tendered', '10000.00')
            ->assertJsonPath('receipt_profile.business_name', 'Nenial Enterprises & Construction')
            ->assertJsonPath('cashier.name', $cashier->name);
        $this->assertDatabaseHas('sales', [
            'id' => $response->json('id'),
            'amount_tendered' => 10000,
        ]);
    }

    public function test_pos_rejects_insufficient_cash_before_recording_the_sale(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $product = Product::firstOrFail();

        $this->actingAs($cashier)->postJson('/api/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => 1,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('amount_tendered');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_non_cash_sale_records_the_total_as_tendered_without_change(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $product = Product::firstOrFail();

        $response = $this->actingAs($cashier)->postJson('/api/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'gcash',
            'amount_tendered' => 999999,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->assertEqualsWithDelta(
            (float) $response->json('total'),
            (float) $response->json('amount_tendered'),
            0.01
        );
        $this->assertSame('0.00', $response->json('change_due'));
    }

    public function test_admin_resolves_password_ticket_with_temporary_password(): void
    {
        $admin = User::where('role', 'admin')->first();
        $admin->update(['password' => 'AdminCurrent2026!']);
        $customer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $ticket = $this->postJson('/api/auth/password-tickets', ['email' => $customer->email, 'reason' => 'Forgot password'])
            ->assertAccepted()->json('ticket_number');
        $ticketId = PasswordResetTicket::where('ticket_number', $ticket)->value('id');

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

    public function test_payroll_cannot_be_finalized_without_active_employees(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        Employee::query()->update(['is_active' => false]);

        $this->actingAs($admin)->postJson('/api/payroll/runs', [
            'period_start' => '2026-07-15',
            'period_end' => '2026-07-21',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Payroll cannot be finalized because there are no active employees.'
            );

        $this->assertDatabaseCount('payroll_runs', 0);
    }

    public function test_finalized_payroll_download_uses_the_immutable_snapshot(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $employee = Employee::where('is_active', true)->firstOrFail();
        $original = [
            'employee_number' => $employee->employee_number,
            'name' => $employee->name,
            'job_title' => $employee->job_title,
        ];

        $this->actingAs($admin)->postJson('/api/payroll/runs', [
            'period_start' => '2026-07-20',
            'period_end' => '2026-07-26',
        ])->assertCreated();

        $run = PayrollRun::with('items')->firstOrFail();
        $item = $run->items->firstWhere('employee_id', $employee->id);
        $this->assertNotNull($item);
        $snapshotBasePay = (float) $item->base_pay;
        $snapshotNetPay = (float) $item->net_pay;

        $employee->update([
            'name' => 'Changed After Finalization',
            'job_title' => 'Changed Role',
            'weekly_salary' => $snapshotBasePay + 9999,
            'incentive' => 9999,
        ]);
        $employee->delete();

        $response = $this->actingAs($admin)->getJson(
            '/api/payroll/export-data?period_start=2026-07-20&period_end=2026-07-26'
        )->assertOk()
            ->assertJsonPath('source', 'finalized')
            ->assertJsonPath('finalized', true)
            ->assertJsonPath('reference', $run->reference);

        $row = collect($response->json('rows'))
            ->firstWhere('employee.employee_number', $original['employee_number']);
        $this->assertNotNull($row);
        $this->assertSame($original['name'], data_get($row, 'employee.name'));
        $this->assertSame($original['job_title'], data_get($row, 'employee.job_title'));
        $this->assertEquals($snapshotBasePay, data_get($row, 'calculation.base_pay'));
        $this->assertEquals($snapshotNetPay, data_get($row, 'calculation.net_pay'));

        $csv = $this->actingAs($admin)->get(
            '/api/payroll/export?period_start=2026-07-20&period_end=2026-07-26'
        )->assertOk()->streamedContent();
        $this->assertStringContainsString($original['name'], $csv);
        $this->assertStringNotContainsString('Changed After Finalization', $csv);
    }

    public function test_sync_configuration_contains_accounts_workforce_and_devices(): void
    {
        config(['offline.sync_token' => 'test-sync-secret']);
        $this->withToken('test-sync-secret')->getJson('/api/sync/configuration')->assertOk()
            ->assertJsonStructure([
                'users' => [['email', 'password_hash', 'role']],
                'employees',
                'devices',
                'statutory_rates' => [['code', 'effective_from', 'rules', 'source_url']],
                'statutory_rate_monitor',
            ]);
    }
}
