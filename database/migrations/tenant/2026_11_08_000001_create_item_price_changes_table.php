<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row each time a selling, cost, channel or price-list price actually changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->string('field', 32);
            $table->decimal('min_quantity', 20, 6)->nullable();
            $table->decimal('old_value', 20, 6)->nullable();
            $table->decimal('new_value', 20, 6)->nullable();
            $table->decimal('change_percent', 12, 4)->nullable();
            $table->string('source', 32);
            $table->string('source_id', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('effective_at');
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'effective_at']);
            $table->index(['effective_at', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_price_changes');
    }
};
