<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Private link that lets this client fill the onboarding form without logging in.
            $table->string('onboarding_token', 64)->nullable()->unique();
            $table->timestamp('onboarded_at')->nullable();
            $table->string('source', 20)->nullable(); // manual | lead | form
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['onboarding_token']);
            $table->dropColumn(['onboarding_token', 'onboarded_at', 'source']);
        });
    }
};
