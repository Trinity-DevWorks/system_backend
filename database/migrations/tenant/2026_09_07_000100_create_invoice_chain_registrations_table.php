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
            $table->string('chain_status', 24)->nullable();
            $table->timestamp('financed_at')->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamp('revoke_requested_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_tx_hash', 66)->nullable();
            $table->text('revoke_error')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->string('dispute_reason_hash', 66)->nullable();
            $table->text('dispute_reason')->nullable();
            $table->string('dispute_tx_hash', 66)->nullable();
            $table->uuid('replaced_by')->nullable();
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
