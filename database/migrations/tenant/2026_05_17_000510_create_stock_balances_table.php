<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity', 14, 6)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('inventory_value', 20, 4)->default(0);
            $table->timestamps();

            $table->index(['item_id', 'warehouse_id', 'lot_id']);
            $table->index('warehouse_id');
            $table->index('lot_id');
        });

        DB::statement('CREATE UNIQUE INDEX stock_balances_item_wh_unlotted_unique ON stock_balances (item_id, warehouse_id) WHERE lot_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX stock_balances_item_wh_lot_unique ON stock_balances (item_id, warehouse_id, lot_id) WHERE lot_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
