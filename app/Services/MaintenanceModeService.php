<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class MaintenanceModeService
{
    public const KEY = 'site_maintenance';

    public const DEFAULT_MESSAGE = 'We are currently performing scheduled maintenance. Please check back shortly.';

    public function enabled(): bool
    {
        return $this->status()['enabled'];
    }

    public function status(): array
    {
        try {
            $setting = SystemSetting::query()->where('key', self::KEY)->first();
        } catch (QueryException $exception) {
            if ($this->isMissingSettingsTable($exception)) {
                // A new deployment must stay reachable until its migration finishes.
                return $this->defaultStatus();
            }

            report($exception);

            return $this->unavailableStatus();
        }

        return $this->format($setting);
    }

    public function update(
        bool $enabled,
        ?string $message,
        ?User $actor,
        string $source = 'admin',
        ?string $startedAt = null,
        ?string $changedAt = null,
    ): array {
        return DB::transaction(function () use ($enabled, $message, $actor, $source, $startedAt, $changedAt) {
            $setting = SystemSetting::query()->where('key', self::KEY)->lockForUpdate()->first();
            $before = $this->format($setting);
            $normalizedMessage = trim((string) $message) ?: self::DEFAULT_MESSAGE;
            $value = [
                'enabled' => $enabled,
                'message' => $normalizedMessage,
                'started_at' => $enabled
                    ? ($startedAt ?: $before['started_at'] ?: now()->toIso8601String())
                    : null,
                'changed_at' => $changedAt ?: now()->toIso8601String(),
                'source' => $source,
            ];

            $setting ??= new SystemSetting(['key' => self::KEY]);
            $setting->forceFill([
                'value' => $value,
                'updated_by' => $actor?->id,
            ])->save();

            $after = $this->format($setting->fresh());
            $this->audit($setting, $actor, $enabled, $before, $after, $source);

            return $after;
        });
    }

    public function applyRemote(array $payload, ?User $actor = null): array
    {
        $current = $this->status();
        $enabled = (bool) ($payload['enabled'] ?? false);
        $message = trim((string) ($payload['message'] ?? '')) ?: self::DEFAULT_MESSAGE;
        $startedAt = $enabled ? ($payload['started_at'] ?? null) : null;
        $incomingUpdatedAt = $payload['updated_at'] ?? null;

        if (
            $incomingUpdatedAt
            && $current['updated_at']
            && CarbonImmutable::parse($incomingUpdatedAt)->lessThan(CarbonImmutable::parse($current['updated_at']))
        ) {
            return $current;
        }

        if (
            $current['enabled'] === $enabled
            && $current['message'] === $message
            && (! $enabled || ! $startedAt || $current['started_at'] === $startedAt)
        ) {
            return $current;
        }

        return $this->update(
            $enabled,
            $message,
            $actor,
            'cloud_sync',
            $startedAt,
            $incomingUpdatedAt,
        );
    }

    private function format(?SystemSetting $setting): array
    {
        $value = $setting?->value ?? [];

        return [
            'enabled' => (bool) ($value['enabled'] ?? false),
            'message' => trim((string) ($value['message'] ?? '')) ?: self::DEFAULT_MESSAGE,
            'started_at' => $value['started_at'] ?? null,
            'updated_at' => $value['changed_at'] ?? $setting?->updated_at?->toIso8601String(),
            'source' => $value['source'] ?? null,
        ];
    }

    private function defaultStatus(): array
    {
        return [
            'enabled' => false,
            'message' => self::DEFAULT_MESSAGE,
            'started_at' => null,
            'updated_at' => null,
            'source' => null,
        ];
    }

    private function unavailableStatus(): array
    {
        return [
            'enabled' => true,
            'message' => 'Store services are temporarily unavailable while maintenance is in progress.',
            'started_at' => null,
            'updated_at' => null,
            'source' => 'database_unavailable',
        ];
    }

    private function isMissingSettingsTable(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'system_settings')
            && (
                in_array((string) $exception->getCode(), ['42P01', '42S02'], true)
                || str_contains($message, 'does not exist')
                || str_contains($message, 'no such table')
                || str_contains($message, 'base table or view not found')
                || str_contains($message, 'undefined table')
            );
    }

    private function audit(
        SystemSetting $setting,
        ?User $actor,
        bool $enabled,
        array $before,
        array $after,
        string $source,
    ): void {
        try {
            AuditLog::create([
                'actor_id' => $actor?->id,
                'action' => $enabled ? 'site.maintenance_enabled' : 'site.maintenance_disabled',
                'auditable_type' => SystemSetting::class,
                'auditable_id' => $setting->id,
                'before' => $before,
                'after' => $after,
                'metadata' => ['source' => $source],
                'ip_address' => request()?->ip(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
