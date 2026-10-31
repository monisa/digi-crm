<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin | employee | client
            $table->string('role', 20)->default('client')->after('password')->index();
            // pending_onboarding | pending_approval | active | inactive
            $table->string('status', 30)->default('active')->after('role')->index();
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('company_name')->nullable()->after('phone');
            $table->json('onboarding_data')->nullable()->after('status');
            $table->timestamp('onboarded_at')->nullable()->after('onboarding_data');
            $table->timestamp('last_login_at')->nullable()->after('onboarded_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'status', 'phone', 'company_name', 'onboarding_data', 'onboarded_at', 'last_login_at']);
        });
    }
};
