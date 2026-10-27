<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_revaluation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('stock_revaluation_id')->constrained('stock_revaluations')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity_on_hand', 14, 6)->nullable();
            $table->decimal('base_quantity', 14, 6)->nullable();
            $table->decimal('old_unit_cost', 14, 4)->nullable();
            $table->decimal('old_inventory_value', 14, 4)->nullable();
            $table->decimal('new_unit_cost', 14, 4);
            $table->decimal('new_inventory_value', 14, 4)->nullable();
            $table->decimal('value_delta', 14, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('stock_revaluation_id');
            $table->index('item_id');
            $table->index('lot_id');
        });

        DB::statement('CREATE UNIQUE INDEX stock_revaluation_lines_item_unlotted_unique ON stock_revaluation_lines (stock_revaluation_id, item_id) WHERE lot_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX stock_revaluation_lines_item_lot_unique ON stock_revaluation_lines (stock_revaluation_id, item_id, lot_id) WHERE lot_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_revaluation_lines');
    }
};
