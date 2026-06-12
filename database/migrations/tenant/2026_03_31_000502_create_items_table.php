<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('sku', 100)->unique();
            $table->string('item_code', 100)->nullable()->unique();
            $table->string('plu_code', 100)->nullable()->unique();
            $table->foreignId('item_type_id')->constrained('item_types')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('unit_group_id')->constrained('unit_groups')->restrictOnDelete();
            $table->foreignId('base_uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->foreignId('vat_group_id')->nullable()->constrained('vat_groups')->nullOnDelete();
            $table->string('description', 500)->nullable();
            $table->string('ticket_name', 120)->nullable();
            $table->string('kitchen_name', 120)->nullable();
            $table->boolean('send_to_kitchen')->default(false);
            $table->boolean('qr_enabled')->default(false);
            $table->string('qr_description', 1000)->nullable();
            $table->string('pos_name', 255)->nullable();
            $table->string('color', 32)->nullable();
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
