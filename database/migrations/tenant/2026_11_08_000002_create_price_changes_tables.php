<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch of new prices, applied immediately or on an effective date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 64)->nullable()->unique();
            $table->date('effective_date');
            $table->string('status', 24);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'effective_date']);
        });

        Schema::create('price_change_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('price_change_id')->constrained('price_changes')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->restrictOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->restrictOnDelete();
            $table->decimal('min_quantity', 20, 6)->nullable();
            $table->string('field', 32);
            $table->decimal('old_value', 20, 6)->nullable();
            $table->decimal('new_value', 20, 6);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('price_change_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_change_lines');
        Schema::dropIfExists('price_changes');
    }
};
