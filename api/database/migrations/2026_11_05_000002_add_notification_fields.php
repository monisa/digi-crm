<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Email on/off per notification type, e.g. {"lead_uncontacted": false}. Missing = on.
            $table->json('notification_prefs')->nullable()->after('clients_scope');
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('sla_alerted_at')->nullable()->after('first_contacted_at');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedTinyInteger('reminders_sent')->default(0)->after('emailed_at');
            $table->timestamp('last_reminder_at')->nullable()->after('reminders_sent');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['reminders_sent', 'last_reminder_at']));
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('sla_alerted_at'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_prefs'));
    }
};
