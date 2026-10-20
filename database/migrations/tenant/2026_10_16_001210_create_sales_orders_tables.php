<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_number', 32)->nullable()->unique();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignUuid('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->foreignId('payment_terms_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->foreignUuid('sales_quotation_id')->nullable()->constrained('sales_quotations')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->date('order_date');
            $table->date('requested_date')->nullable();
            $table->decimal('exchange_rate', 24, 12)->default(1);
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_total', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['order_date', 'status']);
            $table->index('sales_quotation_id');
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('quantity', 14, 6);
            $table->decimal('base_quantity', 14, 6);
            $table->decimal('invoiced_base_quantity', 14, 6)->default(0);
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

            $table->index('sales_order_id');
            $table->index('item_id');
        });

        Schema::table('sales_quotations', function (Blueprint $table) {
            $table->foreignUuid('sales_order_id')->nullable()->after('created_by')->constrained('sales_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_order_id');
        });
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
