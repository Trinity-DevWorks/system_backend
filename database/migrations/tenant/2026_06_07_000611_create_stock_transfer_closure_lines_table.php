<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_closure_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('stock_transfer_closure_id')->constrained('stock_transfer_closures')->cascadeOnDelete();
            $table->foreignId('stock_transfer_line_id')->constrained('stock_transfer_lines')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->string('outcome', 24);
            $table->decimal('quantity', 14, 6);
            $table->decimal('base_quantity', 14, 6);
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->foreignId('stock_adjustment_reason_id')->constrained('stock_adjustment_reasons')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('stock_transfer_closure_id');
            $table->index('stock_transfer_line_id');
            $table->index('item_id');
            $table->index('stock_adjustment_reason_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_closure_lines');
    }
};
