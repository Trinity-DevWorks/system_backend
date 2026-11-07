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
            // A variant parent is a template; its children are real, sellable items.
            $table->uuid('parent_item_id')->nullable();
            $table->boolean('is_variant_parent')->default(false);
            $table->string('name');
            $table->string('sku', 100)->nullable()->unique();
            $table->string('item_code', 100)->nullable()->unique();
            $table->string('plu_code', 100)->nullable()->unique();
            $table->foreignId('item_type_id')->constrained('item_types')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('unit_group_id')->constrained('unit_groups')->restrictOnDelete();
            $table->foreignId('base_uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->foreignId('vat_group_id')->nullable()->constrained('vat_groups')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->string('ticket_name', 120)->nullable();
            $table->string('kitchen_name', 120)->nullable();
            $table->boolean('send_to_kitchen')->default(false);
            $table->boolean('qr_enabled')->default(false);
            $table->string('qr_description', 1000)->nullable();
            $table->string('pos_name', 255)->nullable();
            $table->string('color', 32)->nullable();
            $table->boolean('track_inventory')->default(true);
            $table->boolean('track_lots')->default(false);
            /** null = inherit tenant inventory_costing_method */
            $table->string('costing_method', 32)->nullable();
            $table->boolean('allow_sale')->default(true);
            $table->boolean('allow_purchase')->default(true);
            $table->boolean('allow_return')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_item_id');
            $table->index('item_type_id');
            $table->index('category_id');
            $table->index('brand_id');
            $table->index('vat_group_id');
        });

        // Self-reference: the uuid primary key must exist before the foreign key is added.
        Schema::table('items', function (Blueprint $table) {
            $table->foreign('parent_item_id')->references('id')->on('items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
