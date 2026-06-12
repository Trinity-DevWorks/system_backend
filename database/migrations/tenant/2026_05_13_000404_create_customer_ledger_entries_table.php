<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            /** opening_balance | invoice | payment | … */
            $table->string('reference_type', 64)->index();
            $table->unsignedBigInteger('reference_id')->nullable()->index();
            $table->date('transaction_date')->index();
            $table->timestamps();

            $table->index(['customer_id', 'currency_id', 'transaction_date']);
            $table->index(['customer_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledger_entries');
    }
};
