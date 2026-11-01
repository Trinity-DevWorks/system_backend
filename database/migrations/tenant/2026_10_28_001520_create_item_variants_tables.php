<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attributes such as Size or Color, with their values (S, M, L / Red, Blue).
        Schema::create('item_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('item_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_attribute_id')->constrained('item_attributes')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['item_attribute_id', 'code']);
        });

        Schema::create('item_variant_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('item_attribute_id')->constrained('item_attributes')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'item_attribute_id']);
        });

        Schema::create('item_variant_values', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('item_attribute_value_id')->constrained('item_attribute_values')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'item_attribute_value_id']);
            $table->index('item_attribute_value_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_variant_values');
        Schema::dropIfExists('item_variant_attributes');
        Schema::dropIfExists('item_attribute_values');
        Schema::dropIfExists('item_attributes');
    }
};
