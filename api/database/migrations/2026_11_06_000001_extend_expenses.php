<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 7: receipts, approval, reimbursements, payroll-linked expenses, editable categories. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('receipt_name')->nullable()->after('notes');
            $table->string('receipt_path')->nullable()->after('receipt_name');
            $table->boolean('reimbursable')->default(false)->after('receipt_path'); // paid by a staff member personally
            $table->timestamp('reimbursed_at')->nullable()->after('reimbursable');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('review_note')->nullable()->after('approved_at');
            $table->foreignId('campaign_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            // Expenses created by the system, e.g. "payroll:2026-10" (one per month, updated on republish).
            $table->string('source', 40)->nullable()->unique()->after('created_by');
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        foreach (['Ads spend', 'Salaries', 'Rent', 'Software & tools', 'Travel', 'Office supplies', 'Utilities', 'Food & refreshments', 'Professional fees', 'Misc'] as $i => $name) {
            DB::table('expense_categories')->insert(['name' => $name, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropUnique(['source']);
            $t->dropConstrainedForeignId('approved_by');
            $t->dropConstrainedForeignId('campaign_id');
            $t->dropColumn(['receipt_name', 'receipt_path', 'reimbursable', 'reimbursed_at', 'approved_at', 'review_note', 'source']);
        });
    }
};
