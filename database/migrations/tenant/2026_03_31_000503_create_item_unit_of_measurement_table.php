<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-item alternate UOMs: conversion to inventory base UOM (see items.base_uom_id).
     */
    public function up(): void
    {
        Schema::create('item_unit_of_measurement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('unit_of_measurement_id')->constrained('unit_of_measurements')->cascadeOnDelete();
            $table->enum('operation', ['multiply', 'divide'])->default('multiply');
            $table->decimal('conversion', 14, 6)->default(1);
            $table->decimal('price_1', 12, 2)->nullable();
            $table->decimal('price_2', 12, 2)->nullable();
            $table->decimal('price_3', 12, 2)->nullable();
            $table->decimal('price_4', 12, 2)->nullable();
            $table->decimal('price_5', 12, 2)->nullable();
            $table->decimal('price_6', 12, 2)->nullable();
            $table->decimal('gross_volume', 14, 6)->nullable();
            $table->decimal('gross_weight', 14, 6)->nullable();
            $table->decimal('net_volume', 14, 6)->nullable();
            $table->decimal('net_weight', 14, 6)->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'unit_of_measurement_id']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_unit_of_measurement');
    }
};
