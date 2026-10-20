<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('refund_number', 32)->unique();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12)->default(1);
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->date('refund_date');
            $table->decimal('amount', 20, 4);
            $table->string('reference', 128)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['refund_date', 'status']);
        });

        Schema::create('customer_refund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('customer_refund_id')->constrained('customer_refunds')->cascadeOnDelete();
            $table->foreignUuid('sales_credit_note_id')->constrained('sales_credit_notes')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->decimal('applied_amount', 20, 4);
            $table->decimal('applied_exchange_rate', 24, 12);
            $table->decimal('exchange_difference', 20, 4)->default(0);
            $table->unique(['customer_refund_id', 'sales_credit_note_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_refund_allocations');
        Schema::dropIfExists('customer_refunds');
    }
};
