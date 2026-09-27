<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_chain_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('proof_id');
            $table->string('invoice_type', 16);
            $table->uuid('invoice_id');
            $table->string('status', 16);
            $table->string('tx_hash', 66)->nullable();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->string('contract_address', 42)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('proof_id');
            $table->index(['invoice_type', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_chain_registrations');
    }
};
