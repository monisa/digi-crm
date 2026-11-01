<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 5b: campaigns with results and report files, ticket conversations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('platform', 30); // meta | google | seo | social | website | email | other
            $table->string('objective', 120)->nullable();
            $table->decimal('budget', 12, 2)->nullable(); // ad budget for the period
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 15)->default('planned')->index(); // planned | active | paused | completed
            // Latest results the team shares with the client.
            $table->decimal('spend', 12, 2)->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedInteger('leads')->nullable();
            $table->unsignedInteger('conversions')->nullable();
            $table->text('summary')->nullable(); // "what happened this month" note for the client
            $table->timestamp('results_updated_at')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaign_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('original_name');
            $table->string('path');
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('subject');
            $table->timestamp('last_reply_at')->nullable()->after('resolved_at');
            $table->boolean('awaiting_staff')->default(true)->after('last_reply_at'); // true = client wrote last
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false); // staff-only note, never shown to the client
            $table->string('attachment_name')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn(['category', 'last_reply_at', 'awaiting_staff']));
        Schema::dropIfExists('campaign_files');
        Schema::dropIfExists('campaigns');
    }
};
