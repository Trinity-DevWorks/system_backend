<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            // Highest discount % users with this role may give without a manager's approval (null = no limit).
            $table->decimal('max_discount_percent', 8, 4)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('resource_key', 100)->unique();
            $table->string('resource_label', 150);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_add')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_import')->default(false);
            $table->boolean('can_export')->default(false);
            $table->boolean('can_reverse')->default(false);
            $table->boolean('can_reopen')->default(false);
            $table->boolean('can_print')->default(false);
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::table('branch_user', function (Blueprint $table) {
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
        });

        // A manager's OK given on the spot with their PIN (discount above limit, credit limit, ...).
        Schema::create('manager_approvals', function (Blueprint $table) {
            $table->id();
            // The permission it needed, e.g. discount_override.
            $table->string('action', 64)->index();
            $table->foreignUuid('approver_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('document_type', 64)->nullable();
            $table->uuid('document_id')->nullable();
            $table->string('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::table('branch_user', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
        });

        Schema::dropIfExists('manager_approvals');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
