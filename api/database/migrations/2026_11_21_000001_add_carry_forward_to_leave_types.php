<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Leave policy: carry unused days into the next year; part-year quota for people who join mid-year. */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $t) {
            $t->boolean('carry_forward')->default(false)->after('yearly_quota');
            $t->decimal('carry_max', 5, 1)->nullable()->after('carry_forward');     // most days carried (null = all)
            $t->unsignedSmallInteger('carry_from_year')->nullable()->after('carry_max'); // first year whose leftover is carried
            $t->boolean('prorate_joiners')->default(true)->after('carry_from_year');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $t) {
            $t->dropColumn(['carry_forward', 'carry_max', 'carry_from_year', 'prorate_joiners']);
        });
    }
};
