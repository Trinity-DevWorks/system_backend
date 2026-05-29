<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sellable/pricing UOM rows: conversion to item base UOM, prices per currency.
     */
    public function up(): void
    {
        Schema::create('item_uoms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('uom_id')->constrained('unit_of_measurements')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('conversion_factor', 14, 6)->default(1);
            $table->string('barcode', 128)->nullable();
            $table->decimal('selling_price', 14, 4)->nullable();
            $table->decimal('cost_price', 14, 4)->nullable();
            $table->boolean('is_base')->default(false);
            $table->boolean('is_default_sale')->default(false);
            $table->boolean('is_default_purchase')->default(false);
            $table->timestamps();

            $table->unique(['item_id', 'uom_id', 'currency_id']);
            $table->unique('barcode');
            $table->index('item_id');
            $table->index(['item_id', 'currency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_uoms');
    }
};
