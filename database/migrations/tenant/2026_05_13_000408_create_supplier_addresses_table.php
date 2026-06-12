<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->enum('address_type', ['billing', 'shipping'])->default('shipping');
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city');
            $table->string('state');
            $table->string('country', 100);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['supplier_id', 'address_type', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_addresses');
    }
};
