<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_explosion_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('bundle_explosion_id')->constrained('bundle_explosions')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('bundle_item_id')->nullable()->constrained('bundle_items')->nullOnDelete();
            $table->decimal('quantity', 14, 6);
            $table->decimal('base_quantity', 14, 6);
            $table->decimal('theoretical_quantity', 14, 6);
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('bundle_explosion_id');
            $table->index('item_id');
            $table->index('lot_id');
        });

        DB::statement('CREATE UNIQUE INDEX bundle_explosion_lines_item_unlotted_unique ON bundle_explosion_lines (bundle_explosion_id, item_id) WHERE lot_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX bundle_explosion_lines_item_lot_unique ON bundle_explosion_lines (bundle_explosion_id, item_id, lot_id) WHERE lot_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_explosion_lines');
    }
};
