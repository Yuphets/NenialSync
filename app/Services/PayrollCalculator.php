<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class PayrollCalculator
{
    public function calculate(Employee $employee): array
    {
        $monthly = (float) $employee->weekly_salary * 52 / 12;
        $selected = $employee->deduction_plan ?? ['sss', 'pagibig', 'philhealth'];
        $sssRules = $this->rules('sss', ['employee_rate' => .05, 'min_credit' => 5000, 'max_credit' => 35000]);
        $pagibigRules = $this->rules('pagibig', ['employee_rate' => .02, 'low_income_rate' => .01, 'low_income_ceiling' => 1500, 'max_salary' => 10000]);
        $philhealthRules = $this->rules('philhealth', ['total_rate' => .05, 'employee_share' => .5, 'min_salary' => 10000, 'max_salary' => 100000]);
        $sssCredit = min($sssRules['max_credit'], max($sssRules['min_credit'], round($monthly / 500) * 500));
        $sss = in_array('sss', $selected, true) ? $sssCredit * $sssRules['employee_rate'] * 12 / 52 : 0;
        $pagibigRate = $monthly <= ($pagibigRules['low_income_ceiling'] ?? 1500) ? ($pagibigRules['low_income_rate'] ?? .01) : $pagibigRules['employee_rate'];
        $pagibig = in_array('pagibig', $selected, true) ? min($monthly, $pagibigRules['max_salary']) * $pagibigRate * 12 / 52 : 0;
        $philhealth = in_array('philhealth', $selected, true) ? min($philhealthRules['max_salary'], max($philhealthRules['min_salary'], $monthly)) * $philhealthRules['total_rate'] * $philhealthRules['employee_share'] * 12 / 52 : 0;
        $overtime = (float) $employee->overtime_hours * (float) $employee->overtime_hourly_rate;
        $gross = (float) $employee->weekly_salary + (float) $employee->incentive + $overtime;
        $deductions = $sss + $pagibig + $philhealth;

        return collect(['base_pay' => $employee->weekly_salary, 'incentive' => $employee->incentive, 'overtime_pay' => $overtime, 'gross_pay' => $gross, 'sss' => $sss, 'pagibig' => $pagibig, 'philhealth' => $philhealth, 'other_deductions' => 0, 'net_pay' => $gross - $deductions])->map(fn ($v) => round((float) $v, 2))->all();
    }

    private function rules(string $code, array $fallback): array
    {
        $row = DB::table('statutory_rates')
            ->where('code', $code)
            ->whereDate('effective_from', '<=', today())
            ->orderByDesc('effective_from')
            ->first();

        if (! $row) {
            return $fallback;
        }

        $rules = is_string($row->rules) ? json_decode($row->rules, true) : (array) $row->rules;

        return array_replace($fallback, $rules ?: []);
    }
}
