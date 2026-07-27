<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;

class PayrollSnapshotService
{
    private const MONEY_FIELDS = [
        'base_pay',
        'incentive',
        'overtime_pay',
        'gross_pay',
        'sss',
        'pagibig',
        'philhealth',
        'other_deductions',
        'net_pay',
    ];

    public function __construct(private readonly PayrollCalculator $calculator) {}

    /**
     * Return export-ready rows. A finalized period is always read from its
     * immutable items; only a period that has not been finalized is calculated
     * from the current employee settings.
     */
    public function export(string $periodStart, string $periodEnd): array
    {
        $run = PayrollRun::query()
            ->where('status', 'finalized')
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->with(['items.employee'])
            ->first();

        if ($run) {
            return [
                'source' => 'finalized',
                'finalized' => true,
                'reference' => $run->reference,
                'finalized_at' => $run->finalized_at?->toIso8601String(),
                'rows' => $run->items
                    ->sortBy(fn ($item) => mb_strtolower(
                        $item->employee_name ?: $item->employee?->name ?: ''
                    ))
                    ->values()
                    ->map(fn ($item) => [
                        'employee' => [
                            'id' => $item->employee_id,
                            'employee_number' => $item->employee_number
                                ?: $item->employee?->employee_number,
                            'name' => $item->employee_name ?: $item->employee?->name,
                            'job_title' => $item->job_title ?: $item->employee?->job_title,
                        ],
                        'calculation' => collect(self::MONEY_FIELDS)
                            ->mapWithKeys(fn (string $field) => [$field => (float) $item->{$field}])
                            ->all(),
                    ])
                    ->all(),
            ];
        }

        $rates = $this->calculator->rateBundle($periodEnd);

        return [
            'source' => 'preview',
            'finalized' => false,
            'reference' => null,
            'finalized_at' => null,
            'rows' => Employee::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Employee $employee) => [
                    'employee' => [
                        'id' => $employee->id,
                        'employee_number' => $employee->employee_number,
                        'name' => $employee->name,
                        'job_title' => $employee->job_title,
                    ],
                    'calculation' => $this->calculator->calculate($employee, $periodEnd, $rates),
                ])
                ->all(),
        ];
    }

    /**
     * Determine whether an incoming sync payload represents the exact snapshot
     * already stored for this run. Presentation-only timestamps are excluded;
     * every payroll identity and calculation value is included.
     */
    public function isEquivalent(PayrollRun $run, array $payload): bool
    {
        $run->loadMissing(['creator', 'items.employee']);

        return $this->canonicalRun($run, $payload) === $this->canonicalPayload($payload);
    }

    /**
     * Build the sync payload from stored snapshot fields, with relationship
     * fallbacks for records created before identity columns were introduced.
     */
    public function payload(PayrollRun $run): array
    {
        $run->loadMissing(['creator', 'items.employee']);

        return [
            'reference' => $run->reference,
            'period_start' => $run->period_start->format('Y-m-d'),
            'period_end' => $run->period_end->format('Y-m-d'),
            'status' => $run->status,
            'created_by_email' => $run->created_by_email ?: $run->creator?->email,
            'created_by_name' => $run->created_by_name ?: $run->creator?->name,
            'created_by_account_email' => $run->creator?->email,
            'finalized_at' => $run->finalized_at?->toIso8601String(),
            'items' => $run->items->map(fn ($item) => [
                'employee_number' => $item->employee_number
                    ?: $item->employee?->employee_number,
                'employee_name' => $item->employee_name ?: $item->employee?->name,
                'job_title' => $item->job_title ?: $item->employee?->job_title,
                ...$item->only([...self::MONEY_FIELDS, 'calculation']),
            ])->values()->all(),
        ];
    }

    private function canonicalRun(PayrollRun $run, array $incoming): array
    {
        $incomingItems = collect($incoming['items'] ?? [])
            ->keyBy(fn (array $item) => (string) ($item['employee_number'] ?? ''));

        $snapshot = [
            'reference' => $run->reference,
            'period_start' => $run->period_start->format('Y-m-d'),
            'period_end' => $run->period_end->format('Y-m-d'),
            'status' => $run->status,
            'created_by_email' => $run->created_by_email ?: $run->creator?->email,
            'items' => $run->items->map(function ($item) use ($incomingItems) {
                $employeeNumber = (string) ($item->employee_number
                    ?: $item->employee?->employee_number);
                $incomingItem = $incomingItems->get($employeeNumber, []);
                $snapshot = [
                    'employee_number' => $employeeNumber,
                    ...$item->only([...self::MONEY_FIELDS, 'calculation']),
                ];

                // Older clients did not send these snapshot identity fields.
                // Compare them whenever the sender includes them, without
                // making a legacy retry appear different.
                if (array_key_exists('employee_name', $incomingItem)) {
                    $snapshot['employee_name'] = $item->employee_name
                        ?: $item->employee?->name;
                }
                if (array_key_exists('job_title', $incomingItem)) {
                    $snapshot['job_title'] = $item->job_title
                        ?: $item->employee?->job_title;
                }

                return $snapshot;
            })->values()->all(),
        ];
        if (array_key_exists('created_by_name', $incoming)) {
            $snapshot['created_by_name'] = $run->created_by_name ?: $run->creator?->name;
        }

        return $this->canonicalPayload($snapshot);
    }

    private function canonicalPayload(array $payload): array
    {
        $items = collect($payload['items'] ?? [])->map(function (array $item) {
            $normalized = [
                'employee_number' => trim((string) ($item['employee_number'] ?? '')),
            ];
            if (array_key_exists('employee_name', $item)) {
                $normalized['employee_name'] = trim((string) $item['employee_name']);
            }
            if (array_key_exists('job_title', $item)) {
                $normalized['job_title'] = trim((string) $item['job_title']);
            }
            foreach (self::MONEY_FIELDS as $field) {
                $normalized[$field] = number_format((float) ($item[$field] ?? 0), 2, '.', '');
            }
            $normalized['calculation'] = $this->normalizeValue($item['calculation'] ?? null);

            return $normalized;
        })->sortBy('employee_number')->values()->all();

        $canonical = [
            'reference' => trim((string) ($payload['reference'] ?? '')),
            'period_start' => $this->date($payload['period_start'] ?? null),
            'period_end' => $this->date($payload['period_end'] ?? null),
            'status' => (string) ($payload['status'] ?? ''),
            'created_by_email' => mb_strtolower(trim((string) ($payload['created_by_email'] ?? ''))),
            'items' => $items,
        ];
        if (array_key_exists('created_by_name', $payload)) {
            $canonical['created_by_name'] = trim((string) $payload['created_by_name']);
        }

        return $canonical;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (is_int($value) || is_float($value)) {
                return rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');
            }

            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalizeValue($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->normalizeValue($item), $value);
    }

    private function date(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse($value)->toDateString() : null;
    }

}
