<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('grn_number', 32)->nullable()->unique();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->date('received_date');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['purchase_order_id', 'status']);
            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
