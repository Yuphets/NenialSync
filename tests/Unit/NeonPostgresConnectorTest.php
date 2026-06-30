<?php

namespace Tests\Unit;

use App\Database\NeonPostgresConnector;
use PHPUnit\Framework\TestCase;

class NeonPostgresConnectorTest extends TestCase
{
    public function test_it_adds_the_neon_endpoint_for_an_older_libpq_client(): void
    {
        $connector = new class extends NeonPostgresConnector
        {
            public function dsn(array $config): string
            {
                return $this->getDsn($config);
            }
        };

        $dsn = $connector->dsn([
            'host' => 'ep-cool-darkness-123456-pooler.ap-southeast-1.aws.neon.tech',
            'database' => 'neondb',
            'port' => 5432,
            'sslmode' => 'require',
        ]);

        $this->assertStringContainsString("options='endpoint=ep-cool-darkness-123456'", $dsn);
    }

    public function test_it_does_not_change_a_non_neon_connection(): void
    {
        $connector = new class extends NeonPostgresConnector
        {
            public function dsn(array $config): string
            {
                return $this->getDsn($config);
            }
        };

        $dsn = $connector->dsn(['host' => 'database.internal', 'database' => 'nenial']);

        $this->assertStringNotContainsString('options=', $dsn);
    }
}
