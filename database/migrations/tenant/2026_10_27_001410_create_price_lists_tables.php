<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price lists: per-currency selling prices with validity dates and quantity breaks.
 * Assigned to a customer, a customer group or a branch (resolution order in PriceResolver);
 * item_uoms.selling_price stays the fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'is_default']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('item_uom_id')->constrained('item_uoms')->cascadeOnDelete();
            $table->decimal('min_quantity', 20, 6)->default(0);
            $table->decimal('price', 20, 6);
            $table->timestamps();

            $table->unique(['price_list_id', 'item_id', 'item_uom_id', 'min_quantity'], 'price_list_items_unique_break');
            $table->index(['item_id', 'item_uom_id']);
        });

        foreach (['customers', 'customer_groups', 'branches'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'customer_groups', 'branches'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('price_list_id');
            });
        }

        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
