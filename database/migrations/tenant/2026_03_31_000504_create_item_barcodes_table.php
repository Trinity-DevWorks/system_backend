<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('item_unit_of_measurement_id')->nullable()->constrained('item_unit_of_measurement')->cascadeOnDelete();
            $table->string('barcode')->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('item_id');
            $table->index('item_unit_of_measurement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_barcodes');
    }
};
