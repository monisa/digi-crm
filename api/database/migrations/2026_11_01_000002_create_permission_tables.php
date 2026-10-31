<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('sort')->default(0);
            // Whether this module may ever be granted to the client role.
            $table->boolean('client_allowed')->default(false);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('key', 90)->unique(); // e.g. "leads.view"
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->string('role', 20);
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role', 'permission_id']);
        });

        // Per-user overrides of the role defaults: allowed=true grants, allowed=false denies.
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed');
            $table->primary(['user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('modules');
    }
};
