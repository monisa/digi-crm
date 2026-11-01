<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4: lead fields matching the DigiFox lead sheet, activity log, Meta pages, settings. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('ad_name')->nullable()->after('campaign_name');
            $table->string('adset_name')->nullable()->after('ad_name');
            $table->string('form_name')->nullable()->after('adset_name');
            $table->string('meta_leadgen_id', 40)->nullable()->unique()->after('form_name');
            $table->string('meta_form_id', 40)->nullable()->after('meta_leadgen_id');
            $table->string('call_status', 60)->nullable()->after('status');
            $table->string('package', 120)->nullable()->after('call_status');
            $table->text('requirement')->nullable()->after('package');
            $table->string('followup_status', 120)->nullable()->after('requirement');
            $table->timestamp('next_followup_at')->nullable()->index()->after('followup_status');
            $table->text('notes')->nullable()->after('next_followup_at');
            $table->string('lost_reason')->nullable()->after('notes');
            $table->json('custom_fields')->nullable()->after('lost_reason'); // extra answers from Meta forms
            $table->json('raw_payload')->nullable()->after('custom_fields');
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // call | whatsapp | email | meeting | note | status | assignment | system
            $table->string('type', 20);
            $table->string('outcome', 120)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Which leads a non-admin sees: only those assigned to them, or all.
            $table->string('leads_scope', 10)->default('assigned')->after('status');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('meta_pages', function (Blueprint $table) {
            $table->id();
            $table->string('page_id', 40)->unique();
            $table->string('name')->nullable();
            $table->text('access_token'); // encrypted
            $table->boolean('subscribed')->default(false);
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_pages');
        Schema::dropIfExists('settings');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('leads_scope'));
        Schema::dropIfExists('lead_activities');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['meta_leadgen_id']);
            $table->dropColumn(['ad_name', 'adset_name', 'form_name', 'meta_leadgen_id', 'meta_form_id', 'call_status', 'package',
                'requirement', 'followup_status', 'next_followup_at', 'notes', 'lost_reason', 'custom_fields', 'raw_payload']);
        });
    }
};
