<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_purchase_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('proof_id')->unique();
            $table->string('seller_name');
            $table->string('seller_wallet', 42)->nullable();
            $table->string('seller_wallet_type', 16)->nullable();
            $table->string('invoice_number')->nullable();
            $table->json('disclosure');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_purchase_offers');
    }
};
