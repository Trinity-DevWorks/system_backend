<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 14, 6);
            $table->decimal('base_quantity', 14, 6);
            $table->foreignId('item_uom_id')->nullable()->constrained('item_uoms')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'item_id']);
            $table->index('stock_transfer_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
    }
};
