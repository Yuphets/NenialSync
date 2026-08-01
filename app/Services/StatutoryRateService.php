<?php

namespace App\Services;

use App\Models\StatutoryRate;
use App\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class StatutoryRateService
{
    public const MONITOR_KEY = 'statutory_rate_monitor';

    public const REQUIRED_CODES = ['sss', 'pagibig', 'philhealth'];

    /**
     * Official publication indexes are monitored for changes. A changed page
     * raises an administrator review flag; it never silently changes payroll.
     */
    private const OFFICIAL_MONITORS = [
        'sss' => [
            'label' => 'SSS circular archive',
            'url' => 'https://www.sss.gov.ph/sss-circulars/',
        ],
        'pagibig' => [
            'label' => 'Pag-IBIG Circular 460',
            'url' => 'https://www.pagibigfund.gov.ph/document/pdf/circulars/provident/Circular%20No.%20460%20-%20Guidelines%20on%20the%20Pag-IBIG%20Fund%27s%20Implementation%20of%20Increase%20in%20the%20MFS%20Effective%20February%202024.pdf',
        ],
        'philhealth' => [
            'label' => 'PhilHealth advisory archive',
            'url' => 'https://www.philhealth.gov.ph/advisories/2026/',
        ],
    ];

    public function bundle(CarbonInterface|string|null $asOf = null): array
    {
        $date = $this->date($asOf);
        $records = StatutoryRate::query()
            ->where('status', 'approved')
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date->toDateString());
            })
            ->whereIn('code', self::REQUIRED_CODES)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->groupBy('code');

        $rates = [];
        foreach (self::REQUIRED_CODES as $code) {
            /** @var StatutoryRate|null $record */
            $record = $records->get($code)?->first();
            if (! $record) {
                throw ValidationException::withMessages([
                    'statutory_rates' => "No approved {$this->label($code)} standard is valid for {$date->format('m/d/Y')}. Payroll finalization is blocked.",
                ]);
            }

            $this->validateRules($code, $record->rules ?? []);
            $rates[$code] = $this->serialize($record);
        }

        $checksumPayload = collect($rates)->map(fn (array $rate) => [
            'code' => $rate['code'],
            'revision' => $rate['revision'],
            'effective_from' => $rate['effective_from'],
            'effective_to' => $rate['effective_to'],
            'rules_checksum' => $rate['rules_checksum'],
        ])->values()->all();

        return [
            'as_of' => $date->toDateString(),
            'catalog_checksum' => hash('sha256', json_encode($checksumPayload, JSON_UNESCAPED_SLASHES)),
            'rates' => $rates,
        ];
    }

    public function status(CarbonInterface|string|null $asOf = null): array
    {
        $bundle = $this->bundle($asOf);

        return [
            ...$bundle,
            'rates' => collect($bundle['rates'])->values()->all(),
            'monitor' => $this->monitorStatus(),
        ];
    }

    public function checkOfficialSources(): array
    {
        $previous = $this->monitorStatus();
        $sources = [];

        foreach (self::OFFICIAL_MONITORS as $code => $source) {
            $before = $previous['sources'][$code] ?? [];
            $checkedAt = now()->toIso8601String();

            try {
                $response = Http::accept('*/*')
                    ->withUserAgent('Nenial-Payroll-Standards-Monitor/1.0')
                    ->timeout(10)
                    ->retry(2, 250, throw: false)
                    ->get($source['url']);

                if (! $response->successful() || trim($response->body()) === '') {
                    $sources[$code] = [
                        ...$before,
                        'label' => $source['label'],
                        'url' => $source['url'],
                        'status' => 'unavailable',
                        'http_status' => $response->status(),
                        'last_checked_at' => $checkedAt,
                        'review_required' => (bool) ($before['review_required'] ?? false),
                    ];

                    continue;
                }

                $fingerprint = $this->sourceFingerprint(
                    $response->body(),
                    (string) $response->header('Content-Type'),
                );
                $changed = isset($before['fingerprint'])
                    && ! hash_equals((string) $before['fingerprint'], $fingerprint);
                $sources[$code] = [
                    'label' => $source['label'],
                    'url' => $source['url'],
                    'status' => $changed ? 'changed' : 'current',
                    'http_status' => $response->status(),
                    'fingerprint' => $fingerprint,
                    'last_modified' => $response->header('Last-Modified'),
                    'etag' => $response->header('ETag'),
                    'last_checked_at' => $checkedAt,
                    'review_required' => $changed || (bool) ($before['review_required'] ?? false),
                ];
            } catch (Throwable $exception) {
                report($exception);
                $sources[$code] = [
                    ...$before,
                    'label' => $source['label'],
                    'url' => $source['url'],
                    'status' => 'unavailable',
                    'last_checked_at' => $checkedAt,
                    'review_required' => (bool) ($before['review_required'] ?? false),
                ];
            }
        }

        $value = [
            'last_checked_at' => now()->toIso8601String(),
            'automatic_monitoring' => trim((string) config('services.cron.secret')) !== '',
            'review_required' => collect($sources)->contains(fn (array $source) => $source['review_required']),
            'sources' => $sources,
        ];

        SystemSetting::query()->updateOrCreate(
            ['key' => self::MONITOR_KEY],
            ['value' => $value, 'updated_by' => null],
        );

        return $this->status();
    }

    public function monitorStatus(): array
    {
        $value = SystemSetting::query()->where('key', self::MONITOR_KEY)->value('value');
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? [
            'last_checked_at' => $value['last_checked_at'] ?? null,
            'automatic_monitoring' => (bool) ($value['automatic_monitoring'] ?? (trim((string) config('services.cron.secret')) !== '')),
            'review_required' => (bool) ($value['review_required'] ?? false),
            'sources' => is_array($value['sources'] ?? null) ? $value['sources'] : [],
        ] : [
            'last_checked_at' => null,
            'automatic_monitoring' => trim((string) config('services.cron.secret')) !== '',
            'review_required' => false,
            'sources' => [],
        ];
    }

    public function applyRemoteMonitor(array $incoming): void
    {
        $incomingAt = $incoming['last_checked_at'] ?? null;
        $currentAt = $this->monitorStatus()['last_checked_at'];
        if (! $incomingAt || ($currentAt && CarbonImmutable::parse($incomingAt)->lessThanOrEqualTo(CarbonImmutable::parse($currentAt)))) {
            return;
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => self::MONITOR_KEY],
            [
                'value' => [
                    'last_checked_at' => $incomingAt,
                    'automatic_monitoring' => (bool) ($incoming['automatic_monitoring'] ?? false),
                    'review_required' => (bool) ($incoming['review_required'] ?? false),
                    'sources' => is_array($incoming['sources'] ?? null) ? $incoming['sources'] : [],
                ],
                'updated_by' => null,
            ],
        );
    }

    private function serialize(StatutoryRate $rate): array
    {
        return [
            'id' => $rate->id,
            'code' => $rate->code,
            'label' => $this->label($rate->code),
            'agency' => $rate->agency,
            'revision' => $rate->revision,
            'status' => $rate->status,
            'effective_from' => $rate->effective_from?->toDateString(),
            'effective_to' => $rate->effective_to?->toDateString(),
            'rules' => $rate->rules,
            'rules_checksum' => $rate->rules_checksum,
            'source_title' => $rate->source_title,
            'source_url' => $rate->source_url,
            'published_at' => $rate->published_at?->toDateString(),
            'verified_at' => $rate->verified_at?->toIso8601String(),
        ];
    }

    private function sourceFingerprint(string $body, string $contentType): string
    {
        if (str_contains(strtolower($contentType), 'html') || str_starts_with(ltrim($body), '<')) {
            $body = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $body) ?? $body;
            $body = preg_replace('/<!--.*?-->/s', ' ', $body) ?? $body;
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $body = preg_replace('/\s+/u', ' ', trim($body)) ?? trim($body);
        }

        return hash('sha256', $body);
    }

    private function validateRules(string $code, array $rules): void
    {
        $required = match ($code) {
            'sss' => ['employee_rate', 'min_credit', 'max_credit', 'credit_step'],
            'pagibig' => ['employee_rate', 'low_income_rate', 'low_income_ceiling', 'max_salary'],
            'philhealth' => ['total_rate', 'employee_share', 'min_salary', 'max_salary'],
        };

        foreach ($required as $field) {
            if (! isset($rules[$field]) || ! is_numeric($rules[$field]) || (float) $rules[$field] < 0) {
                throw ValidationException::withMessages([
                    'statutory_rates' => "The approved {$this->label($code)} standard is incomplete ({$field}). Payroll finalization is blocked.",
                ]);
            }
        }

        if (
            ($code === 'sss' && (float) $rules['max_credit'] < (float) $rules['min_credit'])
            || ($code === 'philhealth' && (float) $rules['max_salary'] < (float) $rules['min_salary'])
        ) {
            throw ValidationException::withMessages([
                'statutory_rates' => "The approved {$this->label($code)} standard has invalid contribution limits.",
            ]);
        }
    }

    private function date(CarbonInterface|string|null $asOf): CarbonImmutable
    {
        return $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)->setTimezone(config('app.timezone'))->startOfDay()
            : CarbonImmutable::parse($asOf ?: 'now', config('app.timezone'))->startOfDay();
    }

    private function label(string $code): string
    {
        return match ($code) {
            'sss' => 'SSS',
            'pagibig' => 'Pag-IBIG',
            'philhealth' => 'PhilHealth',
            default => strtoupper($code),
        };
    }
}
