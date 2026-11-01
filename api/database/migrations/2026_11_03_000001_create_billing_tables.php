<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 5a: client details, client logins, GST invoices with items, UPI payment claims. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('industry', 120)->nullable()->after('contact_name');
            $table->string('website')->nullable()->after('phone');
            $table->text('address')->nullable()->after('gstin');
            $table->string('city', 120)->nullable()->after('address');
            $table->string('pincode', 10)->nullable()->after('state');
            $table->json('onboarding_data')->nullable()->after('account_manager_id');
            $table->text('notes')->nullable()->after('onboarding_data');
            $table->foreignId('source_lead_id')->nullable()->after('notes')->constrained('leads')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            // A client login belongs to one client company.
            $table->foreignId('client_id')->nullable()->after('role')->constrained()->nullOnDelete();
            // Which clients a non-admin sees: those they manage, or all.
            $table->string('clients_scope', 10)->default('assigned')->after('leads_scope');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_no', 40)->nullable()->change(); // drafts have no number yet
            $table->string('place_of_supply', 60)->nullable()->after('due_date');
            $table->string('gst_type', 10)->default('intra')->after('place_of_supply'); // intra | inter | export
            $table->decimal('gst_rate', 5, 2)->default(18)->after('gst_type');
            $table->decimal('cgst', 12, 2)->default(0)->after('subtotal');
            $table->decimal('sgst', 12, 2)->default(0)->after('cgst');
            $table->decimal('igst', 12, 2)->default(0)->after('sgst');
            $table->text('notes')->nullable()->after('status');
            $table->timestamp('issued_at')->nullable()->after('notes');
            $table->timestamp('emailed_at')->nullable()->after('issued_at');
            $table->foreignId('created_by')->nullable()->after('emailed_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 500);
            $table->string('sac', 10)->nullable();
            $table->decimal('qty', 10, 2)->default(1);
            $table->decimal('rate', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->after('reference')->constrained('users')->nullOnDelete();
            $table->text('note')->nullable()->after('recorded_by');
        });

        // "I've paid" submissions (UPI QR / bank transfer) waiting for the admin to confirm.
        Schema::create('payment_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('mode', 30)->default('upi');
            $table->string('reference', 60); // UPI transaction ID / UTR
            $table->text('note')->nullable();
            $table->string('status', 15)->default('pending')->index(); // pending | confirmed | rejected
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_claims');
        Schema::table('payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('recorded_by');
            $t->dropColumn('note');
        });
        Schema::dropIfExists('invoice_items');
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn(['place_of_supply', 'gst_type', 'gst_rate', 'cgst', 'sgst', 'igst', 'notes', 'issued_at', 'emailed_at']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('client_id');
            $t->dropColumn('clients_scope');
        });
        Schema::table('clients', function (Blueprint $t) {
            $t->dropConstrainedForeignId('source_lead_id');
            $t->dropColumn(['industry', 'website', 'address', 'city', 'pincode', 'onboarding_data', 'notes']);
        });
    }
};
