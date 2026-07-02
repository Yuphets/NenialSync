<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use App\Services\PayrollCalculator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessAndDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_customer_cannot_adjust_inventory(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->actingAs($customer)->postJson('/api/products/1/adjust', ['quantity_delta' => 1, 'reason' => 'test'])->assertForbidden();
    }

    public function test_facial_device_can_record_attendance(): void
    {
        $token = Str::random(64);
        $device = Device::create(['name' => 'Gate', 'type' => 'facial', 'token_hash' => hash('sha256', $token), 'is_active' => true]);
        $employee = Employee::first();
        $this->withToken($token)->postJson('/api/device/attendance', ['subject_id' => $employee->face_subject_id, 'event_id' => 'evt-001', 'recognized_at' => now()->toIso8601String(), 'confidence' => 98.4])->assertCreated();
        $this->assertDatabaseHas('attendance_records', ['employee_id' => $employee->id, 'device_id' => $device->id, 'status' => 'present']);
    }

    public function test_only_admin_can_list_devices(): void
    {
        $assistant = User::where('role', 'assistant')->first();
        $this->actingAs($assistant)->getJson('/api/devices')->assertForbidden();
    }

    public function test_admin_can_remove_a_mistaken_device(): void
    {
        $admin = User::where('role', 'admin')->first();
        $device = Device::create(['name' => 'Mistaken device', 'type' => 'facial_mobile', 'token_hash' => hash('sha256', Str::random(64)), 'is_active' => true]);

        $this->actingAs($admin)->deleteJson("/api/devices/{$device->id}")->assertNoContent();
        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }

    public function test_logout_returns_the_rotated_csrf_token(): void
    {
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonStructure(['csrf_token']);
    }

    public function test_payroll_uses_the_latest_effective_statutory_rate(): void
    {
        $employee = Employee::first();
        $calculator = app(PayrollCalculator::class);
        $original = $calculator->calculate($employee)['sss'];

        DB::table('statutory_rates')->insert([
            'code' => 'sss',
            'effective_from' => today(),
            'rules' => json_encode(['employee_rate' => .10, 'min_credit' => 5000, 'max_credit' => 35000]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEqualsWithDelta($original * 2, $calculator->calculate($employee)['sss'], .01);
    }

    public function test_assistant_can_select_employee_payroll_deductions(): void
    {
        $assistant = User::where('role', 'assistant')->first();
        $employee = Employee::first();
        $payload = [
            'employee_number' => $employee->employee_number,
            'name' => $employee->name,
            'job_title' => $employee->job_title,
            'weekly_salary' => $employee->weekly_salary,
            'incentive' => $employee->incentive,
            'overtime_hourly_rate' => $employee->overtime_hourly_rate,
            'overtime_hours' => $employee->overtime_hours,
            'deduction_plan' => ['pagibig'],
            'face_subject_id' => $employee->face_subject_id,
        ];

        $this->actingAs($assistant)->putJson("/api/employees/{$employee->id}", $payload)
            ->assertOk()
            ->assertJsonPath('deduction_plan.0', 'pagibig');

        $calculation = app(PayrollCalculator::class)->calculate($employee->fresh());
        $this->assertSame(0.0, $calculation['sss']);
        $this->assertGreaterThan(0, $calculation['pagibig']);
        $this->assertSame(0.0, $calculation['philhealth']);
    }

    public function test_only_admin_can_change_employee_incentive(): void
    {
        $assistant = User::where('role', 'assistant')->first();
        $employee = Employee::first();
        $payload = [
            'employee_number' => $employee->employee_number, 'name' => $employee->name,
            'job_title' => $employee->job_title, 'weekly_salary' => $employee->weekly_salary,
            'incentive' => 1500, 'overtime_hourly_rate' => $employee->overtime_hourly_rate,
            'overtime_hours' => $employee->overtime_hours, 'deduction_plan' => $employee->deduction_plan,
            'face_subject_id' => $employee->face_subject_id,
        ];
        $this->actingAs($assistant)->putJson("/api/employees/{$employee->id}", $payload)->assertForbidden();

        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin)->putJson("/api/employees/{$employee->id}", $payload)->assertOk()->assertJsonPath('incentive', '1500.00');
    }
}
