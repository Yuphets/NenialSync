<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 4)->default(0.12);
            $table->decimal('vatable_sales', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 4)->default(0.12);
            $table->decimal('vatable_sales', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
        });

        foreach (['sales', 'orders'] as $table) {
            DB::table($table)->orderBy('id')->lazyById()->each(function ($record) use ($table) {
                $total = (float) $record->total;
                $vatable = round($total / 1.12, 2);
                DB::table($table)->where('id', $record->id)->update([
                    'vatable_sales' => $vatable,
                    'vat_amount' => round($total - $vatable, 2),
                ]);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->index();
        });

        Schema::create('password_reset_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('ticket_number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();
            $table->text('reason')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tickets');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('must_change_password'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['vat_rate', 'vatable_sales', 'vat_amount']));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['vat_rate', 'vatable_sales', 'vat_amount']));
    }
};
