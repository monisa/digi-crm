<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core business records (see docs/PLAN.md §5). The dashboard reads these from
 * Phase 2; the screens that manage each one arrive in later phases and may add
 * more columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('emp_code', 30)->unique();
            $table->string('name');
            $table->string('office_email')->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->date('dob')->nullable();
            $table->date('doj')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('reporting_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('active')->index(); // active | inactive
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('state')->nullable();
            $table->string('status', 20)->default('active')->index(); // active | inactive
            $table->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone', 30)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('city')->nullable();
            $table->string('source', 30)->default('manual'); // meta | manual | import
            $table->string('platform', 20)->nullable();       // facebook | instagram
            $table->string('campaign_name')->nullable();
            // new | contacted | qualified | proposal | won | lost
            $table->string('status', 20)->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_contacted_at')->nullable();
            $table->foreignId('converted_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_no', 40)->unique();
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('status', 20)->default('unpaid')->index(); // draft | unpaid | partial | paid | cancelled
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on')->index();
            $table->string('mode', 30)->default('bank_transfer');
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('spent_on')->index();
            $table->string('category', 60)->index();
            $table->decimal('amount', 12, 2); // total paid, including GST
            $table->decimal('gst', 12, 2)->default(0);
            $table->string('payment_mode', 30)->nullable();
            $table->string('vendor')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('approved')->index(); // pending | approved | rejected
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->string('priority', 10)->default('medium')->index(); // low | medium | high
            // open | in_progress | waiting | resolved | closed
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique(); // CL | SL
            $table->string('name');
            $table->decimal('yearly_quota', 5, 1)->default(0);
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 5, 1);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected | cancelled
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['leave_requests', 'leave_types', 'tickets', 'expenses', 'payments', 'invoices', 'leads', 'clients', 'employees'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
