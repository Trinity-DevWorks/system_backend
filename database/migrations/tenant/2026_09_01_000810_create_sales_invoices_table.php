<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Issuing branch, frozen once the document leaves draft (see docs/printing-plan.md D3).
            $table->foreignId('branch_id')->index()->constrained('branches')->restrictOnDelete();
            $table->string('invoice_number', 32)->nullable()->unique();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            // Null only on opening invoices.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignUuid('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('payment_terms_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->string('status', 24)->default('draft');
            // Opening documents bring a customer's/supplier's old open balance into the system:
            // no lines, no stock, posted against Opening Balance Equity. original_number = number in the old system.
            $table->boolean('is_opening')->default(false)->index();
            $table->string('original_number', 64)->nullable();
            $table->date('invoice_date');
            $table->date('due_on');
            $table->decimal('exchange_rate', 24, 12)->default(1);
            $table->string('reference_2', 128)->nullable();
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_total', 20, 4)->default(0);
            // Whole-bill discount (percent or amount), spread over the lines before tax.
            $table->string('header_discount_type', 8)->nullable();
            $table->decimal('header_discount_value', 20, 4)->default(0);
            // Who approved a discount above the poster's limit (manager login at the counter).
            $table->foreignUuid('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discount_approved_at')->nullable();
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('adjustment', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->decimal('paid_total', 20, 4)->default(0);
            $table->decimal('credited_total', 20, 4)->default(0);
            $table->decimal('net_to_pay', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['invoice_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
