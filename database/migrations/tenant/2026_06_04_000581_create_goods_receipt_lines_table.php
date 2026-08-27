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
        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 14, 6);
            $table->decimal('base_quantity', 14, 6);
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('goods_receipt_id');
            $table->index('purchase_order_line_id');
            $table->index('item_id');
            $table->index('lot_id');
        });

        DB::statement('CREATE UNIQUE INDEX goods_receipt_lines_po_line_unlotted_unique ON goods_receipt_lines (goods_receipt_id, purchase_order_line_id) WHERE purchase_order_line_id IS NOT NULL AND lot_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX goods_receipt_lines_po_line_lot_unique ON goods_receipt_lines (goods_receipt_id, purchase_order_line_id, lot_id) WHERE purchase_order_line_id IS NOT NULL AND lot_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX goods_receipt_lines_item_unlotted_unique ON goods_receipt_lines (goods_receipt_id, item_id) WHERE purchase_order_line_id IS NULL AND lot_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX goods_receipt_lines_item_lot_unique ON goods_receipt_lines (goods_receipt_id, item_id, lot_id) WHERE purchase_order_line_id IS NULL AND lot_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
    }
};
