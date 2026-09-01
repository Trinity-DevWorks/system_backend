<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('invoice_number', 32)->nullable()->unique();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('payment_terms_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignUuid('goods_receipt_id')->nullable()->constrained('goods_receipts')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('supplier_reference', 128)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['supplier_id', 'status']);
            $table->index(['invoice_date', 'status']);
            $table->index(['purchase_order_id', 'status']);
            $table->index(['goods_receipt_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
