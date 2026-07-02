<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'demo.user@nenial.test')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Do not reactivate a publicly documented credential during rollback.
    }
};
