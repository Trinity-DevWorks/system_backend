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
        Schema::create('production_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('production_id')->constrained('productions')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('recipe_item_id')->nullable()->constrained('recipe_items')->nullOnDelete();
            $table->decimal('quantity', 14, 6);
            $table->decimal('base_quantity', 14, 6);
            $table->decimal('theoretical_quantity', 14, 6);
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('production_id');
            $table->index('item_id');
            $table->index('lot_id');
        });

        DB::statement('CREATE UNIQUE INDEX production_lines_item_unlotted_unique ON production_lines (production_id, item_id) WHERE lot_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX production_lines_item_lot_unique ON production_lines (production_id, item_id, lot_id) WHERE lot_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('production_lines');
    }
};
