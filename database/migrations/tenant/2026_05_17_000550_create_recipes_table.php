<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('item_id')->unique()->constrained('items')->cascadeOnDelete();
            $table->decimal('yield_quantity', 14, 6)->default(1);
            $table->foreignId('uom_id')->constrained('unit_of_measurements')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
