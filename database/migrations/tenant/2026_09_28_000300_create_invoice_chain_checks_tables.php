<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_chain_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 16);
            $table->string('blockchain_network', 16);
            $table->string('contract_address', 42);
            $table->unsignedInteger('checked_count')->default(0);
            $table->unsignedInteger('issue_count')->default(0);
            $table->unsignedInteger('requeued_count')->default(0);
            $table->string('scanned_supplier', 42)->nullable();
            $table->unsignedBigInteger('scanned_from_block')->nullable();
            $table->unsignedBigInteger('scanned_to_block')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->index(['blockchain_network', 'contract_address', 'created_at']);
        });

        Schema::create('invoice_chain_check_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('check_id')->constrained('invoice_chain_checks')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->uuid('proof_id')->nullable();
            $table->string('chain_proof_id', 66)->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->text('expected')->nullable();
            $table->text('actual')->nullable();
            $table->timestamps();

            $table->index('check_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_chain_check_issues');
        Schema::dropIfExists('invoice_chain_checks');
    }
};
