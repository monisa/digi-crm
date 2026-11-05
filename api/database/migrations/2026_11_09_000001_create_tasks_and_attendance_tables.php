<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_date')->nullable()->index();
            $table->string('priority', 10)->default('normal'); // low | normal | high | urgent
            $table->string('status', 15)->default('todo')->index(); // todo | in_progress | done
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('minutes_spent')->default(0);
            // Repeating tasks: the first task is the series; copies point to it.
            $table->string('repeat', 10)->default('none'); // none | daily | weekly | monthly
            $table->foreignId('series_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['assigned_to', 'status', 'due_date']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('mode', 10)->default('office'); // office | field | wfh
            $table->boolean('late')->default(false);
            $table->decimal('in_lat', 10, 7)->nullable();
            $table->decimal('in_lng', 10, 7)->nullable();
            $table->unsignedInteger('in_accuracy')->nullable(); // metres
            $table->unsignedInteger('in_distance')->nullable(); // metres from the office
            $table->decimal('out_lat', 10, 7)->nullable();
            $table->decimal('out_lng', 10, 7)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->boolean('auto_closed')->default(false); // forgot to check out
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('tasks');
    }
};
