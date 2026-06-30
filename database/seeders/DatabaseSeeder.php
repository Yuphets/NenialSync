<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Nenial Administrator', 'email' => 'admin@nenial.com', 'role' => 'admin', 'password' => env('SEED_ADMIN_PASSWORD', 'ChangeMeAdmin2026!')],
            ['name' => 'Nenial Assistant Administrator', 'email' => 'assistant@nenial.com', 'role' => 'assistant', 'password' => env('SEED_ASSISTANT_PASSWORD', 'ChangeMeAssistant2026!')],
            ['name' => 'Nenial Cashier', 'email' => 'cashier@nenial.com', 'role' => 'cashier', 'password' => env('SEED_CASHIER_PASSWORD', 'ChangeMeCashier2026!')],
            ['name' => 'Demo Customer', 'email' => 'demo.user@nenial.test', 'role' => 'user', 'password' => env('SEED_DEMO_PASSWORD', 'UserDemo2026!')],
        ];
        foreach ($users as $u) {
            User::updateOrCreate(['email' => $u['email']], ['name' => $u['name'], 'role' => $u['role'], 'is_active' => true, 'password' => Hash::make($u['password'])]);
        }
        $products = [
            ['name' => 'Portland Cement 40kg', 'sku' => 'CON-001', 'barcode' => '480901000001', 'category' => 'Materials', 'supplier' => 'BuildMix PH', 'unit' => 'bags', 'price' => 285, 'stock_quantity' => 180, 'reorder_level' => 25, 'image_url' => '/media/Background.jpg'],
            ['name' => 'Deformed Steel Bar 10mm', 'sku' => 'CON-002', 'barcode' => '480901000002', 'category' => 'Materials', 'supplier' => 'MetroSteel', 'unit' => 'pcs', 'price' => 165, 'stock_quantity' => 240, 'reorder_level' => 30],
            ['name' => 'Formwork Plywood 3/4 in', 'sku' => 'CON-003', 'barcode' => '480901000003', 'category' => 'Finishing', 'supplier' => 'SiteWood Supply', 'unit' => 'sheets', 'price' => 1180, 'stock_quantity' => 48, 'discount_percent' => 5, 'reorder_level' => 10],
            ['name' => 'Washed Sand', 'sku' => 'AGG-001', 'barcode' => '480901000004', 'category' => 'Aggregates', 'supplier' => 'North Quarry', 'unit' => 'cubic', 'price' => 1250, 'stock_quantity' => 10, 'safety_stock' => 2, 'reorder_level' => 3],
            ['name' => 'Crushed Gravel', 'sku' => 'AGG-002', 'barcode' => '480901000005', 'category' => 'Aggregates', 'supplier' => 'North Quarry', 'unit' => 'cubic', 'price' => 1380, 'stock_quantity' => 12, 'safety_stock' => 2, 'reorder_level' => 3],
            ['name' => 'Fine Filling Sand', 'sku' => 'AGG-003', 'barcode' => '480901000006', 'category' => 'Aggregates', 'supplier' => 'Riverbed Supply', 'unit' => 'cubic', 'price' => 980, 'stock_quantity' => 10, 'safety_stock' => 2, 'reorder_level' => 3],
            ['name' => 'Safety Helmet with Chin Strap', 'sku' => 'CON-005', 'barcode' => '480901000007', 'category' => 'Safety', 'supplier' => 'HardHat Depot', 'unit' => 'pcs', 'price' => 390, 'stock_quantity' => 36, 'discount_percent' => 10, 'reorder_level' => 10],
            ['name' => 'Masonry Tool Set', 'sku' => 'CON-006', 'barcode' => '480901000008', 'category' => 'Tools', 'supplier' => 'ToolPro Manila', 'unit' => 'sets', 'price' => 1450, 'stock_quantity' => 18, 'reorder_level' => 5],
        ];
        foreach ($products as $p) {
            Product::updateOrCreate(['sku' => $p['sku']], array_merge(['discount_percent' => 0, 'reserved_quantity' => 0, 'safety_stock' => 0, 'is_active' => true], $p));
        }
        $employees = [['EMP-1001', 'Ramon Dela Cruz', 'Mason', 5100, 'FACE-1001'], ['EMP-1002', 'Jun Ortega', 'Carpenter', 5600, 'FACE-1002'], ['EMP-1003', 'Lito Garcia', 'Steelman', 5520, 'FACE-1003'], ['EMP-1004', 'Mark Villanueva', 'Painter', 4800, 'FACE-1004'], ['EMP-1005', 'Edwin Salazar', 'Tile Setter', 6200, 'FACE-1005'], ['EMP-1006', 'Paolo Manalo', 'Helper Laborer', 3900, 'FACE-1006']];
        foreach ($employees as [$number,$name,$title,$salary,$face]) {
            Employee::updateOrCreate(['employee_number' => $number], ['name' => $name, 'job_title' => $title, 'weekly_salary' => $salary, 'overtime_hourly_rate' => round($salary / 48 * 1.25, 2), 'deduction_plan' => ['sss', 'pagibig', 'philhealth'], 'face_subject_id' => $face, 'is_active' => true]);
        }
        foreach (['sss' => ['employee_rate' => .05, 'min_credit' => 5000, 'max_credit' => 35000], 'pagibig' => ['employee_rate' => .02, 'max_salary' => 10000], 'philhealth' => ['total_rate' => .05, 'employee_share' => .5, 'min_salary' => 10000, 'max_salary' => 100000]] as $code => $rules) {
            DB::table('statutory_rates')->updateOrInsert(['code' => $code, 'effective_from' => '2025-01-01'], ['rules' => json_encode($rules), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
