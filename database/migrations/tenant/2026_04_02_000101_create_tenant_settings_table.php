<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('primary_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('country', 2)->nullable();
            $table->string('preferred_language', 8)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('date_format', 32)->default('Y-m-d');
            $table->string('number_format', 32)->default('comma_dot');
            $table->boolean('tax_enabled')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->string('price_rounding_mode', 32)->default('half_up');
            $table->unsignedTinyInteger('price_decimal_places')->default(2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
