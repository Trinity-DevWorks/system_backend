<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->string('lot_number', 64);
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'lot_number']);
            $table->index(['item_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
