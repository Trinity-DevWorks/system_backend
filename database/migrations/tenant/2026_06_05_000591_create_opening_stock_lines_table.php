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
        Schema::create('opening_stock_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('opening_stock_id')->constrained('opening_stocks')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 14, 6);
            $table->decimal('base_quantity', 14, 6);
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('opening_stock_id');
            $table->index('item_id');
            $table->index('lot_id');
        });

        DB::statement('CREATE UNIQUE INDEX opening_stock_lines_item_unlotted_unique ON opening_stock_lines (opening_stock_id, item_id) WHERE lot_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX opening_stock_lines_item_lot_unique ON opening_stock_lines (opening_stock_id, item_id, lot_id) WHERE lot_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_stock_lines');
    }
};
