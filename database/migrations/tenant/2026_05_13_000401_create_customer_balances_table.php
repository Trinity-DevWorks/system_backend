<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            /** Snapshot / policy; live AR balance for the currency comes from the ledger */
            $table->decimal('opening_balance', 20, 4)->default(0);
            /** As-of date for the opening balance posting (ledger transaction_date) */
            $table->date('opening_date')->nullable();
            $table->decimal('credit_limit', 20, 4)->default(0);
            $table->timestamps();

            $table->unique(['customer_id', 'currency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_balances');
    }
};
