<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 10);
            $table->string('iso_code', 10);
            $table->string('symbol', 16)->nullable();
            $table->decimal('smallest_unit', 20, 6)->nullable();
            $table->decimal('round_limit', 20, 6)->nullable();
            $table->decimal('acceptable_amount_overdue', 20, 4)->nullable();
            $table->decimal('allowed_difference_in_receipt', 20, 4)->nullable();
            $table->decimal('allowed_difference_in_payment', 20, 4)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('code');
            $table->unique('iso_code');
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
