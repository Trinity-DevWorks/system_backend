<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant usage collected from each tenant schema (hourly `tenants:refresh-usage`, or on demand),
 * so the central Usage page reads one table instead of opening every tenant database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_usage_snapshots', function (Blueprint $table) {
            $table->string('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('active_users')->default(0);
            $table->unsignedInteger('total_users')->default(0);
            $table->unsignedInteger('branches')->default(0);
            $table->unsignedInteger('warehouses')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->unsignedInteger('sales_invoices_month')->default(0);
            $table->unsignedInteger('purchase_invoices_month')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage_snapshots');
    }
};
