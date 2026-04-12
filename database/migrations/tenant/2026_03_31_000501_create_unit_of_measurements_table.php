<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_of_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_group_id')->constrained('unit_groups')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('symbol', 32)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['unit_group_id', 'code']);
            $table->unique(['unit_group_id', 'name']);
            $table->index('unit_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_of_measurements');
    }
};
