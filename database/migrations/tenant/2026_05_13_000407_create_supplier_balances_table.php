<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            /** Snapshot / policy; live AP balance for the currency comes from the ledger */
            $table->decimal('opening_balance', 20, 4)->default(0);
            /** As-of date for the opening balance posting (ledger transaction_date) */
            $table->date('opening_date')->nullable();
            $table->decimal('credit_limit', 20, 4)->default(0);
            $table->timestamps();

            $table->unique(['supplier_id', 'currency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_balances');
    }
};
