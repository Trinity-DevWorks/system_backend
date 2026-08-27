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
        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('theoretical_quantity', 14, 6);
            $table->decimal('counted_quantity', 14, 6);
            $table->decimal('variance_quantity', 14, 6);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('stock_count_id');
            $table->index('item_id');
            $table->index('lot_id');
        });

        DB::statement('CREATE UNIQUE INDEX stock_count_lines_item_unlotted_unique ON stock_count_lines (stock_count_id, item_id) WHERE lot_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX stock_count_lines_item_lot_unique ON stock_count_lines (stock_count_id, item_id, lot_id) WHERE lot_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
    }
};
