<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (platform) RBAC. Kept separate from tenant `roles` / `permissions`
 * because tenant roles are branch-scoped through `branch_user.role_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('central_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('resource_key', 100)->unique();
            $table->string('resource_label', 150);
            $table->timestamps();
        });

        Schema::create('central_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('central_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('central_permissions')->cascadeOnDelete();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_add')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_import')->default(false);
            $table->boolean('can_export')->default(false);
            $table->boolean('can_reverse')->default(false);
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('central_role_id')
                ->nullable()
                ->after('is_active')
                ->constrained('central_roles')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('central_role_id');
        });

        Schema::dropIfExists('central_role_permissions');
        Schema::dropIfExists('central_permissions');
        Schema::dropIfExists('central_roles');
    }
};
