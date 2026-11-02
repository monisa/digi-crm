<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Staff accounts that were stuck on the client onboarding form. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', '!=', 'client')->where('status', 'pending_onboarding')->update(['status' => 'active']);
    }

    public function down(): void {}
};
