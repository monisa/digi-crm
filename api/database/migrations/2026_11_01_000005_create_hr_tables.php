<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 3: salary structures, payslips, holidays and leave policy details. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            // Monthly earnings
            $table->decimal('basic', 12, 2);
            $table->decimal('hra', 12, 2)->default(0);
            $table->decimal('special_allowance', 12, 2)->default(0);
            $table->decimal('other_allowance', 12, 2)->default(0);
            // Monthly deductions
            $table->decimal('pf', 12, 2)->default(0);
            $table->decimal('esi', 12, 2)->default(0);
            $table->decimal('professional_tax', 12, 2)->default(0);
            $table->decimal('tds', 12, 2)->default(0);
            $table->decimal('other_deduction', 12, 2)->default(0);
            $table->string('bank_account_last4', 4)->nullable(); // shown masked on payslips
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('days_in_month');
            $table->decimal('lop_days', 5, 1)->default(0);
            $table->json('earnings');   // snapshot: {"Basic": 20000, ...} after loss-of-pay
            $table->json('deductions'); // snapshot: {"Provident Fund": 1800, ..., "Loss of pay": 0}
            $table->decimal('gross', 12, 2);
            $table->decimal('total_deductions', 12, 2);
            $table->decimal('net', 12, 2);
            $table->string('status', 20)->default('draft')->index(); // draft | published
            $table->timestamp('published_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'year', 'month']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->boolean('optional')->default(false); // restricted / optional holiday
            $table->timestamps();
        });

        Schema::table('leave_types', function (Blueprint $table) {
            // Loss-of-pay leave has no quota and reduces salary.
            $table->boolean('paid')->default(true)->after('yearly_quota');
            $table->boolean('active')->default(true)->after('paid');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('half_day')->default(false)->after('to_date');
            $table->text('approver_comment')->nullable()->after('approver_id');
        });

        $now = now();
        foreach ([['CL', 'Casual leave', 4, true], ['SL', 'Sick leave', 6, true], ['LOP', 'Loss of pay', 0, false]] as [$code, $name, $quota, $paid]) {
            DB::table('leave_types')->updateOrInsert(['code' => $code], [
                'name' => $name, 'yearly_quota' => $quota, 'paid' => $paid, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_requests', fn (Blueprint $t) => $t->dropColumn(['half_day', 'approver_comment']));
        Schema::table('leave_types', fn (Blueprint $t) => $t->dropColumn(['paid', 'active']));
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('salary_structures');
    }
};
