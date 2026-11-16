<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** WhatsApp Business messages sent from / received by the CRM. */
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $t) {
            $t->id();
            $t->string('phone', 20)->index();          // international digits, e.g. 919876543210
            $t->string('name', 120)->nullable();       // WhatsApp profile name (incoming)
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who sent it
            $t->string('direction', 3);                // in | out
            $t->string('type', 20)->default('text');   // text, template, image, document, …
            $t->text('body')->nullable();
            $t->string('template', 120)->nullable();
            $t->string('wa_id', 128)->nullable()->unique(); // Meta's message id
            $t->string('status', 12)->default('sent'); // sent, delivered, read, failed, received
            $t->string('error', 255)->nullable();
            $t->timestamp('seen_at')->nullable();      // incoming: opened in the CRM
            $t->timestamps();
            $t->index(['lead_id', 'id']);
            $t->index(['client_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
