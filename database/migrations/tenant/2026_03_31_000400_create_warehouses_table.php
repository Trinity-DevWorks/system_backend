<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('shortcut_name', 50);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_default_production')->default(false);
            $table->boolean('is_default_purchase')->default(false);
            $table->boolean('is_default_storage')->default(false);
            $table->timestamps();

            $table->unique(['name']);
            $table->unique(['shortcut_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
