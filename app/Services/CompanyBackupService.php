<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompanyBackupService
{
    public function export(): array
    {
        return [
            'metadata' => [
                'format' => 'nenial-company-backup', 'version' => 1,
                'generated_at' => now()->toIso8601String(), 'timezone' => config('app.timezone'),
                'security_note' => 'Passwords, session tokens, device tokens, OAuth identifiers, and facial descriptors are intentionally excluded.',
            ],
            'users' => User::withTrashed()->orderBy('id')->get([
                'id', 'name', 'email', 'role', 'is_active', 'email_verified_at',
                'password_changed_at', 'must_change_password', 'created_at', 'updated_at', 'deleted_at',
            ]),
            'products' => Product::withTrashed()->orderBy('id')->get(),
            'sales' => DB::table('sales')->orderBy('id')->get(),
            'sale_items' => DB::table('sale_items')->orderBy('id')->get(),
            'orders' => DB::table('orders')->orderBy('id')->get(),
            'order_items' => DB::table('order_items')->orderBy('id')->get(),
            'inventory_movements' => DB::table('inventory_movements')->orderBy('id')->get(),
            'employees' => Employee::withTrashed()->orderBy('id')->get(),
            'attendance_records' => DB::table('attendance_records')->orderBy('id')->get(),
            'payroll_runs' => DB::table('payroll_runs')->orderBy('id')->get(),
            'payroll_items' => DB::table('payroll_items')->orderBy('id')->get(),
            'statutory_rates' => DB::table('statutory_rates')->orderBy('id')->get(),
            'devices' => Device::orderBy('id')->get([
                'id', 'name', 'type', 'location', 'provider', 'external_id',
                'configuration', 'is_active', 'last_seen_at', 'created_at', 'updated_at',
            ]),
            'audit_logs' => DB::table('audit_logs')->orderBy('id')->get(),
            'sync_conflicts' => DB::table('sync_conflicts')->orderBy('id')->get(),
        ];
    }
}
