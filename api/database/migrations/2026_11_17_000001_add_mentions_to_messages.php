<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** People @mentioned in a chat message (user ids). */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->json('mentions')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->dropColumn('mentions');
        });
    }
};
