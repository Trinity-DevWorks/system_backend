<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            /** stockable | service | non_stock */
            $table->string('type', 32)->default('stockable')->index();
            $table->foreignId('base_uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->foreignId('purchase_uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->foreignId('sales_uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
