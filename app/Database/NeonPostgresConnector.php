<?php

namespace App\Database;

use Illuminate\Database\Connectors\PostgresConnector;

class NeonPostgresConnector extends PostgresConnector
{
    protected function getDsn(array $config)
    {
        $dsn = parent::getDsn($config);
        $endpoint = $this->endpoint($config);

        return $endpoint ? $dsn.";options='endpoint={$endpoint}'" : $dsn;
    }

    private function endpoint(array $config): ?string
    {
        $endpoint = $config['neon_endpoint'] ?? null;

        if (! $endpoint && isset($config['host'])) {
            $hostLabel = explode('.', strtolower((string) $config['host']))[0];
            $endpoint = preg_replace('/-pooler$/', '', $hostLabel);
        }

        return is_string($endpoint) && preg_match('/^ep-[a-z0-9-]+$/', $endpoint)
            ? $endpoint
            : null;
    }
}
