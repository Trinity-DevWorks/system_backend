<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central directory of each tenant's company wallets.
 * Other tenants match a customer or supplier wallet against this list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_company_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->unique();
            $table->string('company_name');
            $table->string('wallet_address_anvil', 42)->nullable()->unique();
            $table->string('wallet_type_anvil', 16)->nullable();
            $table->string('wallet_address_sepolia', 42)->nullable()->unique();
            $table->string('wallet_type_sepolia', 16)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_company_wallets');
    }
};
