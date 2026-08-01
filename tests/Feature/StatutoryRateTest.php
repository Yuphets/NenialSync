<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\PayrollCalculator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StatutoryRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_current_approved_catalog_is_visible_to_payroll_roles(): void
    {
        $assistant = User::where('role', 'assistant')->firstOrFail();

        $this->actingAs($assistant)->getJson('/api/statutory-rates')
            ->assertOk()
            ->assertJsonCount(3, 'rates')
            ->assertJsonPath('rates.0.status', 'approved')
            ->assertJsonStructure([
                'as_of',
                'catalog_checksum',
                'rates' => [['code', 'revision', 'effective_from', 'rules', 'source_url', 'verified_at']],
                'monitor' => ['last_checked_at', 'automatic_monitoring', 'review_required', 'sources'],
            ]);
    }

    public function test_calculator_uses_explicit_period_and_sss_bracket_boundaries(): void
    {
        $calculator = app(PayrollCalculator::class);
        $employee = Employee::make([
            'weekly_salary' => 5249 * 12 / 52,
            'incentive' => 0,
            'overtime_hours' => 0,
            'overtime_hourly_rate' => 0,
            'deduction_plan' => ['sss'],
        ]);
        $belowBoundary = $calculator->calculate($employee, '2026-07-27')['sss'];

        $employee->weekly_salary = 5250 * 12 / 52;
        $atBoundary = $calculator->calculate($employee, '2026-07-27')['sss'];

        $this->assertEqualsWithDelta(5000 * .05 * 12 / 52, $belowBoundary, .01);
        $this->assertEqualsWithDelta(5500 * .05 * 12 / 52, $atBoundary, .01);

        DB::table('statutory_rates')->insert([
            'code' => 'sss',
            'revision' => 'Future approved test',
            'status' => 'approved',
            'effective_from' => '2030-01-01',
            'rules' => json_encode([
                'employee_rate' => .10,
                'min_credit' => 5000,
                'max_credit' => 35000,
                'credit_step' => 500,
            ]),
            'rules_checksum' => hash('sha256', 'future-sss-test'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employee->weekly_salary = 10000 * 12 / 52;
        $before = $calculator->calculate($employee, '2029-12-31')['sss'];
        $after = $calculator->calculate($employee, '2030-01-01')['sss'];
        $this->assertEqualsWithDelta($before * 2, $after, .02);
    }

    public function test_pagibig_and_philhealth_caps_match_current_standards(): void
    {
        $employee = Employee::make([
            'weekly_salary' => 120000 * 12 / 52,
            'incentive' => 0,
            'overtime_hours' => 0,
            'overtime_hourly_rate' => 0,
            'deduction_plan' => ['pagibig', 'philhealth'],
        ]);

        $values = app(PayrollCalculator::class)->calculate($employee, '2026-07-27');
        $this->assertEqualsWithDelta(200 * 12 / 52, $values['pagibig'], .01);
        $this->assertEqualsWithDelta(2500 * 12 / 52, $values['philhealth'], .01);
    }

    public function test_finalized_payroll_snapshots_the_applied_rate_catalog(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        $response = $this->actingAs($admin)->postJson('/api/payroll/runs', [
            'period_start' => '2026-07-20',
            'period_end' => '2026-07-26',
        ])->assertCreated();

        $snapshot = $response->json('items.0.calculation');
        $this->assertSame('2026-07-26', $snapshot['payroll_period_end']);
        $this->assertArrayHasKey('catalog_checksum', $snapshot['statutory_rate_catalog']);
        $this->assertSame('Circular 2024-006', $snapshot['statutory_rate_catalog']['rates']['sss']['revision']);
    }

    public function test_official_source_monitor_flags_changed_content_without_changing_rates(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $requestCount = 0;
        Http::fake(function () use (&$requestCount) {
            $requestCount++;

            return Http::response(
                $requestCount <= 3
                    ? 'official publication baseline'
                    : 'official publication changed',
                200,
            );
        });

        $first = $this->actingAs($admin)
            ->postJson('/api/admin/statutory-rates/check')
            ->assertOk()
            ->assertJsonPath('monitor.review_required', false)
            ->json('catalog_checksum');

        $this->actingAs($admin)
            ->postJson('/api/admin/statutory-rates/check')
            ->assertOk()
            ->assertJsonPath('monitor.review_required', true)
            ->assertJsonPath('catalog_checksum', $first);
    }
}
