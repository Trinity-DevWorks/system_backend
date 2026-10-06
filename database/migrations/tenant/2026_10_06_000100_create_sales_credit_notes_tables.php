<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('credit_note_number', 32)->nullable()->unique();
            $table->foreignUuid('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->date('credit_date');
            $table->decimal('exchange_rate', 24, 12)->default(1);
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_total', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['sales_invoice_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('sales_credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('sales_credit_note_id')->constrained('sales_credit_notes')->cascadeOnDelete();
            $table->foreignId('sales_invoice_line_id')->constrained('sales_invoice_lines')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('quantity', 14, 6);
            $table->decimal('base_quantity', 14, 6);
            $table->decimal('conversion_factor', 14, 6)->default(1);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_percent', 8, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('line_subtotal', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);
            $table->string('description', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('sales_credit_note_id');
            $table->index('sales_invoice_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_note_lines');
        Schema::dropIfExists('sales_credit_notes');
    }
};
