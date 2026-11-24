<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans (central): modules + limits + price, and each tenant's plan and subscription dates.
 * A tenant's modules always equal its plan's modules. Null limits are unlimited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('billing_cycle', 16)->default('monthly');
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_warehouses')->nullable();
            $table->unsignedInteger('max_storage_mb')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('module_code');
            $table->foreign('module_code')->references('code')->on('modules')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'module_code']);
        });

        // Added here (not in the tenants migration) because `plans` must exist first for the foreign key.
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('suspension_reason')->constrained('plans')->restrictOnDelete();
            $table->date('subscription_starts_at')->nullable()->after('plan_id');
            $table->date('subscription_ends_at')->nullable()->after('subscription_starts_at');
            $table->date('trial_ends_at')->nullable()->after('subscription_ends_at');

            $table->index('subscription_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['subscription_ends_at']);
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['subscription_starts_at', 'subscription_ends_at', 'trial_ends_at']);
        });

        Schema::dropIfExists('plan_modules');
        Schema::dropIfExists('plans');
    }
};
