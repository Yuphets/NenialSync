<?php

namespace App\Services;

use RuntimeException;

class SyncUserSignatureService
{
    public function sign(string $nodeId, string $eventId, array $payload): string
    {
        return hash_hmac('sha256', $this->canonicalMessage($nodeId, $eventId, $payload), $this->secret());
    }

    public function verify(string $nodeId, string $eventId, array $payload, string $signature): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $signature) === 1
            && hash_equals($this->sign($nodeId, $eventId, $payload), $signature);
    }

    private function canonicalMessage(string $nodeId, string $eventId, array $payload): string
    {
        unset($payload['sync_signature']);

        return json_encode(
            $this->canonicalize([
                'event_id' => $eventId,
                'node_id' => $nodeId,
                'payload' => $payload,
            ]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
    }

    private function secret(): string
    {
        $secret = (string) config('offline.privileged_secret');
        if ($secret === '') {
            throw new RuntimeException('Privileged account synchronization is not configured.');
        }

        return $secret;
    }
}
