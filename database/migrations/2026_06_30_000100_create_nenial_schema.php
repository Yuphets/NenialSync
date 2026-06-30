<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 24)->default('user')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('password_changed_at')->nullable();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique();
            $table->string('barcode')->unique();
            $table->string('category')->index();
            $table->string('supplier')->nullable();
            $table->string('unit', 32)->default('pcs');
            $table->decimal('price', 14, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('safety_stock')->default(0);
            $table->integer('reorder_level')->default(10);
            $table->unsignedBigInteger('version')->default(1);
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category', 'is_active']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('channel', 24)->default('pos');
            $table->string('payment_method', 40);
            $table->string('status', 24)->default('completed')->index();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('sku');
            $table->integer('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('preparing')->index();
            $table->string('payment_status', 32)->default('on_hold')->index();
            $table->string('payment_method', 40);
            $table->string('payment_reference')->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('sku');
            $table->integer('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32)->index();
            $table->integer('quantity_delta');
            $table->integer('reserved_delta')->default(0);
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->integer('reserved_before');
            $table->integer('reserved_after');
            $table->nullableMorphs('reference');
            $table->string('reason')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['product_id', 'created_at']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('employee_number')->unique();
            $table->string('name');
            $table->string('job_title');
            $table->decimal('weekly_salary', 14, 2);
            $table->decimal('incentive', 14, 2)->default(0);
            $table->decimal('overtime_hourly_rate', 14, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->json('deduction_plan')->nullable();
            $table->string('face_subject_id')->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32)->index();
            $table->string('location')->nullable();
            $table->string('provider')->nullable();
            $table->string('external_id')->nullable()->unique();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->json('configuration')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->date('attendance_date')->index();
            $table->string('status', 24)->default('present')->index();
            $table->timestamp('recognized_at')->nullable();
            $table->decimal('match_confidence', 5, 2)->nullable();
            $table->string('provider_event_id')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'attendance_date']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('base_pay', 14, 2);
            $table->decimal('incentive', 14, 2)->default(0);
            $table->decimal('overtime_pay', 14, 2)->default(0);
            $table->decimal('gross_pay', 14, 2);
            $table->decimal('sss', 14, 2)->default(0);
            $table->decimal('pagibig', 14, 2)->default(0);
            $table->decimal('philhealth', 14, 2)->default(0);
            $table->decimal('other_deductions', 14, 2)->default(0);
            $table->decimal('net_pay', 14, 2);
            $table->json('calculation')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('statutory_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->index();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('rules');
            $table->timestamps();
            $table->unique(['code', 'effective_from']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->nullableMorphs('auditable');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('statutory_rates');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('products');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'password_changed_at']);
        });
    }
};
