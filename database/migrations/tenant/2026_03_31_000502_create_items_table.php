<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku', 100)->unique();
            $table->string('plu_code', 100)->nullable()->unique();
            $table->foreignId('item_type_id')->constrained('item_types')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('base_uom_id')->constrained('unit_of_measurements')->restrictOnDelete();
            $table->foreignId('vat_group_id')->nullable()->constrained('vat_groups')->nullOnDelete();
            $table->string('description', 500)->nullable();
            $table->boolean('track_inventory')->default(true);
            $table->boolean('allow_sale')->default(true);
            $table->boolean('allow_purchase')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('item_type_id');
            $table->index('category_id');
            $table->index('brand_id');
            $table->index('vat_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
