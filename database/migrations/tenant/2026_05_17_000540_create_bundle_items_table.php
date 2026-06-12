<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('bundle_item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignUuid('child_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 14, 6)->default(1);
            $table->timestamps();

            $table->unique(['bundle_item_id', 'child_item_id']);
            $table->index('bundle_item_id');
            $table->index('child_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
    }
};
