<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('supplier_sku', 100)->nullable();
            $table->decimal('last_purchase_price', 14, 4)->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->unsignedInteger('lead_time_days')->default(0);
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();

            $table->unique(['supplier_id', 'item_id']);
            $table->index('item_id');
            $table->index(['item_id', 'is_preferred']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_items');
    }
};
