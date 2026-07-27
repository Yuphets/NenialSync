<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\CarbonInterface;

class PayrollCalculator
{
    public function __construct(private readonly StatutoryRateService $rates) {}

    public function rateBundle(CarbonInterface|string|null $asOf = null): array
    {
        return $this->rates->bundle($asOf);
    }

    public function calculate(
        Employee $employee,
        CarbonInterface|string|null $asOf = null,
        ?array $rateBundle = null,
    ): array {
        $rateBundle ??= $this->rateBundle($asOf);
        $monthly = (float) $employee->weekly_salary * 52 / 12;
        $selected = $employee->deduction_plan ?? ['sss', 'pagibig', 'philhealth'];
        $sssRules = $rateBundle['rates']['sss']['rules'];
        $pagibigRules = $rateBundle['rates']['pagibig']['rules'];
        $philhealthRules = $rateBundle['rates']['philhealth']['rules'];
        $sssStep = (float) $sssRules['credit_step'];
        $sssCredit = min(
            (float) $sssRules['max_credit'],
            max(
                (float) $sssRules['min_credit'],
                floor(($monthly + ($sssStep / 2)) / $sssStep) * $sssStep,
            ),
        );
        $sss = in_array('sss', $selected, true) ? $sssCredit * $sssRules['employee_rate'] * 12 / 52 : 0;
        $pagibigRate = $monthly <= $pagibigRules['low_income_ceiling'] ? $pagibigRules['low_income_rate'] : $pagibigRules['employee_rate'];
        $pagibig = in_array('pagibig', $selected, true) ? min($monthly, $pagibigRules['max_salary']) * $pagibigRate * 12 / 52 : 0;
        $philhealth = in_array('philhealth', $selected, true) ? min($philhealthRules['max_salary'], max($philhealthRules['min_salary'], $monthly)) * $philhealthRules['total_rate'] * $philhealthRules['employee_share'] * 12 / 52 : 0;
        $overtime = (float) $employee->overtime_hours * (float) $employee->overtime_hourly_rate;
        $gross = (float) $employee->weekly_salary + (float) $employee->incentive + $overtime;
        $deductions = $sss + $pagibig + $philhealth;

        return collect(['base_pay' => $employee->weekly_salary, 'incentive' => $employee->incentive, 'overtime_pay' => $overtime, 'gross_pay' => $gross, 'sss' => $sss, 'pagibig' => $pagibig, 'philhealth' => $philhealth, 'other_deductions' => 0, 'net_pay' => $gross - $deductions])->map(fn ($v) => round((float) $v, 2))->all();
    }
}
