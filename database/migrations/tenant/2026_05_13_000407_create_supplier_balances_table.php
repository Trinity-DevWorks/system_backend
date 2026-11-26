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
            // Credit policy per currency. Old open balances are opening invoices / opening credits.
            $table->decimal('credit_limit', 20, 4)->default(0);
            // Credit - debit of the supplier's ledger rows in this currency, kept up to date on each posting.
            $table->decimal('balance', 20, 4)->default(0);
            $table->timestamps();

            $table->unique(['supplier_id', 'currency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_balances');
    }
};
