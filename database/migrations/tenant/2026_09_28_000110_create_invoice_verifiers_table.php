<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_verifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('role', 16);
            $table->string('party_side', 16)->default('supplier');
            $table->string('wallet_address', 42);
            $table->unique(['wallet_address', 'party_side']);
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('notes')->nullable();
            $table->string('chain_status', 16);
            $table->string('chain_company_wallet', 42)->nullable();
            $table->string('chain_tx_hash', 66)->nullable();
            $table->text('chain_error')->nullable();
            $table->timestamp('chain_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_verifiers');
    }
};
