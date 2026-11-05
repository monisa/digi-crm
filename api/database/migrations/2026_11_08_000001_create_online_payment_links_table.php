<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Razorpay payment links made for invoices (one per amount asked for).
        Schema::create('online_payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('razorpay');
            $table->string('link_id', 60)->unique();
            $table->string('url');
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('created'); // created | paid | cancelled | expired
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('gateway_fee', 10, 2)->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('gateway_fee'));
        Schema::dropIfExists('online_payment_links');
    }
};
