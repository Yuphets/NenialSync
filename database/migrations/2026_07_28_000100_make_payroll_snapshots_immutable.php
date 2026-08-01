<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->string('employee_number', 80)->nullable()->after('employee_id');
            $table->string('employee_name', 190)->nullable()->after('employee_number');
            $table->string('job_title', 120)->nullable()->after('employee_name');
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('created_by_email', 190)->nullable()->after('created_by');
            $table->string('created_by_name', 190)->nullable()->after('created_by_email');
        });

        // Preserve the employee identity that was visible when each historical
        // payroll item was created. Monetary values were already snapshotted.
        DB::table('payroll_items')
            ->orderBy('id')
            ->eachById(function ($item): void {
                $employee = DB::table('employees')->where('id', $item->employee_id)->first();
                if (! $employee) {
                    return;
                }

                DB::table('payroll_items')->where('id', $item->id)->update([
                    'employee_number' => $employee->employee_number,
                    'employee_name' => $employee->name,
                    'job_title' => $employee->job_title,
                ]);
            });

        DB::table('payroll_runs')
            ->orderBy('id')
            ->eachById(function ($run): void {
                $creator = DB::table('users')->where('id', $run->created_by)->first();
                DB::table('payroll_runs')->where('id', $run->id)->update([
                    'created_by_email' => $creator?->email,
                    'created_by_name' => $creator?->name,
                ]);
            });

        // Earlier releases could create multiple finalized runs for one period.
        // Preserve every snapshot, but keep only the newest one active so the
        // partial unique index can protect all future finalizations.
        $duplicatePeriods = DB::table('payroll_runs')
            ->select(['period_start', 'period_end'])
            ->where('status', 'finalized')
            ->groupBy(['period_start', 'period_end'])
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($duplicatePeriods as $period) {
            $keeper = DB::table('payroll_runs')
                ->where('status', 'finalized')
                ->where('period_start', $period->period_start)
                ->where('period_end', $period->period_end)
                ->orderByDesc('finalized_at')
                ->orderByDesc('id')
                ->value('id');
            DB::table('payroll_runs')
                ->where('status', 'finalized')
                ->where('period_start', $period->period_start)
                ->where('period_end', $period->period_end)
                ->where('id', '!=', $keeper)
                ->update(['status' => 'superseded', 'updated_at' => now()]);
        }

        DB::statement(
            "CREATE UNIQUE INDEX payroll_runs_period_start_period_end_unique
             ON payroll_runs (period_start, period_end)
             WHERE status = 'finalized'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payroll_runs_period_start_period_end_unique');

        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn(['employee_number', 'employee_name', 'job_title']);
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['created_by_email', 'created_by_name']);
        });
    }
};
