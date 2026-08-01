<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_rates', function (Blueprint $table) {
            $table->string('agency', 120)->nullable()->after('code');
            $table->string('revision', 80)->nullable()->after('agency');
            $table->string('status', 24)->default('approved')->index()->after('revision');
            $table->string('source_title')->nullable()->after('rules');
            $table->text('source_url')->nullable()->after('source_title');
            $table->date('published_at')->nullable()->after('source_url');
            $table->timestampTz('verified_at')->nullable()->after('published_at');
            $table->timestampTz('approved_at')->nullable()->after('verified_at');
            $table->string('rules_checksum', 64)->nullable()->after('approved_at');
        });

        $verifiedAt = '2026-07-27 00:00:00';
        $catalog = [
            [
                'code' => 'sss',
                'agency' => 'Social Security System',
                'revision' => 'Circular 2024-006',
                'effective_from' => '2025-01-01',
                'published_at' => '2024-12-19',
                'source_title' => 'SSS Circular No. 2024-006 and 2025 Contribution Table',
                'source_url' => 'https://www.sss.gov.ph/wp-content/uploads/2024/12/CI-2024-006-Publication.pdf',
                'rules' => [
                    'employee_rate' => 0.05,
                    'employer_rate' => 0.10,
                    'min_credit' => 5000,
                    'max_credit' => 35000,
                    'credit_step' => 500,
                    'regular_ss_ceiling' => 20000,
                ],
            ],
            [
                'code' => 'pagibig',
                'agency' => 'Pag-IBIG Fund (HDMF)',
                'revision' => 'Circular 460',
                'effective_from' => '2024-02-01',
                'published_at' => '2024-01-15',
                'source_title' => 'Pag-IBIG Fund Circular No. 460',
                'source_url' => 'https://www.pagibigfund.gov.ph/document/pdf/circulars/provident/Circular%20No.%20460%20-%20Guidelines%20on%20the%20Pag-IBIG%20Fund%27s%20Implementation%20of%20Increase%20in%20the%20MFS%20Effective%20February%202024.pdf',
                'rules' => [
                    'employee_rate' => 0.02,
                    'employer_rate' => 0.02,
                    'low_income_rate' => 0.01,
                    'low_income_ceiling' => 1500,
                    'max_salary' => 10000,
                ],
            ],
            [
                'code' => 'philhealth',
                'agency' => 'Philippine Health Insurance Corporation',
                'revision' => 'Premium Advisory 2025-0002',
                'effective_from' => '2025-01-01',
                'published_at' => '2025-02-05',
                'source_title' => 'PhilHealth Premium Contribution for CY 2025',
                'source_url' => 'https://www.philhealth.gov.ph/advisories/2025/PA2025-0002.pdf',
                'rules' => [
                    'total_rate' => 0.05,
                    'employee_share' => 0.50,
                    'employer_share' => 0.50,
                    'min_salary' => 10000,
                    'max_salary' => 100000,
                ],
            ],
        ];

        // Retire the old seed row whose 2025 effective date did not match
        // Pag-IBIG Circular 460's February 2024 implementation date.
        DB::table('statutory_rates')
            ->where('code', 'pagibig')
            ->whereDate('effective_from', '2025-01-01')
            ->update(['status' => 'superseded', 'updated_at' => now()]);

        foreach ($catalog as $standard) {
            $rules = json_encode($standard['rules'], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $values = [
                'agency' => $standard['agency'],
                'revision' => $standard['revision'],
                'status' => 'approved',
                'effective_to' => null,
                'rules' => $rules,
                'source_title' => $standard['source_title'],
                'source_url' => $standard['source_url'],
                'published_at' => $standard['published_at'],
                'verified_at' => $verifiedAt,
                'approved_at' => $verifiedAt,
                'rules_checksum' => hash('sha256', $rules),
                'updated_at' => now(),
            ];
            $existing = DB::table('statutory_rates')
                ->where('code', $standard['code'])
                ->whereDate('effective_from', $standard['effective_from'])
                ->first();

            if ($existing) {
                DB::table('statutory_rates')->where('id', $existing->id)->update($values);
            } else {
                DB::table('statutory_rates')->insert([
                    'code' => $standard['code'],
                    'effective_from' => $standard['effective_from'],
                    ...$values,
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('statutory_rates', function (Blueprint $table) {
            $table->dropColumn([
                'agency',
                'revision',
                'status',
                'source_title',
                'source_url',
                'published_at',
                'verified_at',
                'approved_at',
                'rules_checksum',
            ]);
        });
    }
};
