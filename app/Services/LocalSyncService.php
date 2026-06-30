<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SyncConflict;
use App\Models\SyncOutbox;
use App\Models\SyncState;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LocalSyncService
{
    public function run(): array
    {
        $this->assertConfigured();
        $synced = 0;
        $conflicts = 0;

        foreach (SyncOutbox::whereIn('status', ['pending', 'failed'])->orderBy('id')->limit(100)->get() as $event) {
            $response = $this->push($event);
            $event->increment('attempts');

            if ($response->successful()) {
                $event->update(['status' => 'synced', 'synced_at' => now(), 'last_error' => null]);
                $synced++;

                continue;
            }

            $message = $response->json('message') ?: $response->body();
            if ($response->unprocessableEntity() || $response->status() === 409) {
                $event->update(['status' => 'conflict', 'last_error' => $message]);
                SyncConflict::updateOrCreate(
                    ['event_id' => $event->event_id],
                    ['outbox_id' => $event->id, 'event_type' => $event->event_type, 'reason' => mb_substr($message, 0, 255), 'local_payload' => $event->payload, 'remote_response' => $response->json(), 'status' => 'open']
                );
                $conflicts++;

                continue;
            }

            $event->update(['status' => 'failed', 'last_error' => mb_substr($message, 0, 2000)]);

            return $this->status(false, $synced, $conflicts, $message);
        }

        if (SyncOutbox::whereIn('status', ['pending', 'failed', 'conflict'])->exists()) {
            return $this->status(true, $synced, $conflicts, 'Cloud inventory pull paused until pending events and conflicts are resolved.');
        }

        $products = $this->client()->get($this->url('/api/sync/products'));
        if (! $products->successful()) {
            return $this->status(false, $synced, $conflicts, $products->body());
        }

        DB::transaction(function () use ($products) {
            foreach ($products->json() as $remote) {
                $product = Product::withTrashed()->firstOrNew(['sku' => $remote['sku']]);
                $product->fill(collect($remote)->only([
                    'name', 'barcode', 'category', 'supplier', 'unit', 'price', 'discount_percent',
                    'stock_quantity', 'reserved_quantity', 'safety_stock', 'reorder_level', 'version', 'image_url', 'is_active',
                ])->all());
                $product->deleted_at = $remote['deleted_at'];
                $product->save();
            }
        });

        SyncState::updateOrCreate(['key' => 'cloud'], ['value' => ['products' => count($products->json())], 'last_synced_at' => now()]);

        return $this->status(true, $synced, $conflicts);
    }

    public function status(bool $online = true, int $synced = 0, int $conflicts = 0, ?string $message = null): array
    {
        return [
            'enabled' => (bool) config('offline.enabled'),
            'node_id' => config('offline.node_id'),
            'online' => $online,
            'pending' => SyncOutbox::whereIn('status', ['pending', 'failed'])->count(),
            'conflicts' => SyncConflict::where('status', 'open')->count(),
            'synced_now' => $synced,
            'conflicts_now' => $conflicts,
            'last_synced_at' => SyncState::where('key', 'cloud')->value('last_synced_at'),
            'message' => $message,
        ];
    }

    private function push(SyncOutbox $event): Response
    {
        $path = match ($event->event_type) {
            'sale.completed' => '/api/sync/sales',
            'attendance.recorded' => '/api/sync/attendance',
            default => throw new RuntimeException("Unsupported sync event {$event->event_type}."),
        };

        return $this->client()->post($this->url($path), [
            'node_id' => config('offline.node_id'),
            'event_id' => $event->event_id,
            'payload' => $event->payload,
        ]);
    }

    private function client()
    {
        return Http::acceptJson()->withToken(config('offline.sync_token'))->timeout(config('offline.timeout'))->retry(2, 500, throw: false);
    }

    private function url(string $path): string
    {
        return config('offline.cloud_url').$path;
    }

    private function assertConfigured(): void
    {
        if (! config('offline.enabled') || ! config('offline.cloud_url') || ! config('offline.sync_token')) {
            throw new RuntimeException('Local offline synchronization is not fully configured.');
        }
    }
}
