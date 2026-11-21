<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Students mark a day's attendance for a manager to approve; managers upload notes for each date. */
    public function up(): void
    {
        Schema::create('student_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $t->date('date');
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 12)->default('pending'); // pending, approved, rejected
            $t->string('note', 500)->nullable();          // from the student
            $t->string('review_comment', 500)->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('wa_status', 12)->nullable();       // WhatsApp to the reviewer: sent, failed, off
            $t->string('wa_error', 255)->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'date']);
            $t->index(['reviewer_id', 'status']);
        });

        Schema::create('class_notes', function (Blueprint $t) {
            $t->id();
            $t->date('date')->index();
            $t->string('title', 160);
            $t->text('body')->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_name')->nullable();
            $t->string('file_mime', 120)->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_notes');
        Schema::dropIfExists('student_attendances');
    }
};
