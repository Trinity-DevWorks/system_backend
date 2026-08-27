<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant in-app notification inbox (Laravel database notifications).
 *
 * What: Stores durable per-user notification rows for the header bell / inbox API.
 * Used for: Phase 1 in-app channel via Laravel's database notification driver.
 * Solves: Gives each tenant user a persistent read/unread inbox without a custom schema reinventing Laravel's Notifiable contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
