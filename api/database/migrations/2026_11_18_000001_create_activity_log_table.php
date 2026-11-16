<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Who did what, and when (Settings → Activity log). */
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('user_name', 120)->nullable(); // kept if the person is later removed
            $t->string('action', 30);                 // created, updated, deleted, login, …
            $t->string('subject_type', 40)->nullable(); // lead, client, invoice, …
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('label', 200)->nullable();
            $t->json('changes')->nullable();           // {field: [old, new]}
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['subject_type', 'subject_id']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
