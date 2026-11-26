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
            // Issuing branch, frozen once the document leaves draft (see docs/printing-plan.md D3).
            $table->foreignId('branch_id')->index()->constrained('branches')->restrictOnDelete();
            $table->string('credit_note_number', 32)->nullable()->unique(); // assigned at posting
            // Null only on opening credits.
            $table->foreignUuid('sales_invoice_id')->nullable()->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12);
            $table->string('type', 16);
            $table->date('credit_date');
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
            // Opening documents bring a customer's/supplier's old open balance into the system:
            // no lines, no stock, posted against Opening Balance Equity. original_number = number in the old system.
            $table->boolean('is_opening')->default(false)->index();
            $table->string('original_number', 64)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            // Do this document's prices include VAT? Fixed when it is created (main price list, else the company
            // setting; copied from the document it is made from).
            $table->boolean('prices_include_tax')->default(false);
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['sales_invoice_id', 'status']);
            $table->index(['credit_date', 'status']);
        });

        Schema::create('sales_credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('sales_credit_note_id')->constrained('sales_credit_notes')->cascadeOnDelete();
            $table->foreignId('sales_invoice_line_id')->constrained('sales_invoice_lines')->restrictOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->decimal('quantity', 14, 6)->default(0);
            $table->decimal('base_quantity', 14, 6)->default(0);
            $table->decimal('conversion_factor', 14, 6)->default(1);
            $table->decimal('unit_price', 20, 6)->default(0);
            $table->decimal('discount_percent', 20, 4)->default(0);
            $table->decimal('tax_rate', 20, 4)->default(0);
            $table->decimal('line_subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);
            $table->boolean('restock')->default(true);
            // Returned goods: restock (back in stock, cost of sales reduced) | waste (stays out, cost moves to Waste).
            $table->string('return_action', 16)->nullable();
            // Cost moved from cost of sales to the Waste account for a waste return.
            $table->decimal('waste_value', 20, 4)->default(0);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });

        Schema::create('sales_credit_note_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('sales_credit_note_id')->constrained('sales_credit_notes')->cascadeOnDelete();
            $table->foreignUuid('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->boolean('is_automatic')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sales_credit_note_id', 'sales_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_note_allocations');
        Schema::dropIfExists('sales_credit_note_lines');
        Schema::dropIfExists('sales_credit_notes');
    }
};
