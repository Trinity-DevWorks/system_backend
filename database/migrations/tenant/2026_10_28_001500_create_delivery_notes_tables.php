<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery notes (optional step: sales order -> delivery note(s) -> invoice).
 * A posted delivery note moves the stock and books the cost; an invoice line made from it
 * only books revenue. sales_order_lines.delivered_base_quantity counts goods that left the
 * warehouse (by delivery note or by a stock-moving direct invoice) and drives reservations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('branch_id')->index()->constrained('branches')->restrictOnDelete();
            $table->string('delivery_number', 32)->nullable()->unique(); // assigned at posting
            $table->foreignUuid('sales_order_id')->nullable()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('delivery_date');
            $table->json('shipping_address')->nullable();
            $table->string('status', 16)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'delivery_date']);
            $table->index(['customer_id', 'status']);
            $table->index('sales_order_id');
        });

        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('delivery_note_id')->constrained('delivery_notes')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('quantity', 14, 6);
            $table->decimal('base_quantity', 14, 6);
            $table->decimal('conversion_factor', 14, 6)->default(1);
            $table->decimal('invoiced_base_quantity', 14, 6)->default(0);
            $table->string('description', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('delivery_note_id');
            $table->index('sales_order_line_id');
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->foreignId('delivery_note_line_id')->nullable()->after('sales_order_line_id')->constrained('delivery_note_lines')->restrictOnDelete();
            $table->index('delivery_note_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_note_line_id');
        });
        Schema::dropIfExists('delivery_note_lines');
        Schema::dropIfExists('delivery_notes');
    }
};
