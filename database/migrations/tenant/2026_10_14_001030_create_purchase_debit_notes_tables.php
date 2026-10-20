<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_debit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('debit_note_number', 32)->unique();
            $table->foreignUuid('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12);
            $table->string('type', 16);
            $table->date('debit_date');
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_total', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('adjustment', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->decimal('applied_total', 20, 4)->default(0);
            $table->decimal('refunded_total', 20, 4)->default(0);
            $table->decimal('open_credit', 20, 4)->default(0);
            $table->string('status', 24)->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['supplier_id', 'status']);
            $table->index(['purchase_invoice_id', 'status']);
            $table->index(['debit_date', 'status']);
        });

        Schema::create('purchase_debit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('purchase_debit_note_id')->constrained('purchase_debit_notes')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_line_id')->constrained('purchase_invoice_lines')->restrictOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->decimal('quantity', 14, 6)->default(0);
            $table->decimal('base_quantity', 14, 6)->default(0);
            $table->decimal('conversion_factor', 14, 6)->default(1);
            $table->decimal('unit_price', 20, 4)->default(0);
            $table->decimal('discount_percent', 20, 4)->default(0);
            $table->decimal('tax_rate', 20, 4)->default(0);
            $table->decimal('line_subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });

        Schema::create('purchase_debit_note_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('purchase_debit_note_id')->constrained('purchase_debit_notes')->cascadeOnDelete();
            $table->foreignUuid('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->boolean('is_automatic')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['purchase_debit_note_id', 'purchase_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_debit_note_allocations');
        Schema::dropIfExists('purchase_debit_note_lines');
        Schema::dropIfExists('purchase_debit_notes');
    }
};
