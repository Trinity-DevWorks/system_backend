<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('source_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->decimal('quantity_remaining', 14, 6);
            $table->decimal('unit_cost', 14, 4);
            $table->timestamps();

            $table->index(['item_id', 'warehouse_id', 'id']);
            $table->index(['item_id', 'warehouse_id', 'lot_id']);
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_layers');
    }
};
