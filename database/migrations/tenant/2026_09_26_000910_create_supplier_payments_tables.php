<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payment_number', 32)->unique();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12)->default(1);
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 20, 4);
            $table->string('reference', 128)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['supplier_id', 'status']);
            $table->index(['payment_date', 'status']);
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignUuid('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->decimal('applied_amount', 20, 4);
            $table->decimal('applied_exchange_rate', 24, 12);
            $table->unique(['supplier_payment_id', 'purchase_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
    }
};
