<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $timestamp = now();
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'site_maintenance',
            'value' => json_encode([
                'enabled' => false,
                'message' => 'We are currently performing scheduled maintenance. Please check back shortly.',
                'started_at' => null,
                'changed_at' => $timestamp->toIso8601String(),
                'source' => 'installation',
            ], JSON_THROW_ON_ERROR),
            'updated_by' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->where('key', 'site_maintenance')->delete();
        }
    }
};
