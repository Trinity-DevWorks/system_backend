<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->decimal('quantity', 14, 6);
            $table->decimal('base_quantity', 14, 6);
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->decimal('unit_price', 14, 4);
            $table->foreignId('vat_group_id')->nullable()->constrained('vat_groups')->nullOnDelete();
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_subtotal', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('purchase_invoice_id');
            $table->index('item_id');
            $table->index('purchase_order_line_id');
            $table->index('goods_receipt_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_lines');
    }
};
