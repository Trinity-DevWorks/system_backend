<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_warehouse_replenishments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('safety_stock_qty', 14, 6)->default(0);
            $table->decimal('reorder_point_qty', 14, 6);
            $table->decimal('reorder_qty', 14, 6)->nullable();
            $table->decimal('max_qty', 14, 6)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']);
            $table->index('warehouse_id');
            $table->index(['is_active', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_warehouse_replenishments');
    }
};
