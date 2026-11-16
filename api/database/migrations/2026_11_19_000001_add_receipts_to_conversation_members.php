<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Read ticks: up to which message each member has received (app polled) and read, and when. */
    public function up(): void
    {
        Schema::table('conversation_members', function (Blueprint $t) {
            $t->unsignedBigInteger('delivered_id')->default(0)->after('last_read_id');
            $t->timestamp('delivered_at')->nullable()->after('delivered_id');
            $t->timestamp('read_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_members', function (Blueprint $t) {
            $t->dropColumn(['delivered_id', 'delivered_at', 'read_at']);
        });
    }
};
