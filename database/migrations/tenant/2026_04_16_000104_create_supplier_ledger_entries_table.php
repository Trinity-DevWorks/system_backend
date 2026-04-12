<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            /** Amounts paid to the supplier (reduces payable) */
            $table->decimal('debit', 20, 4)->default(0);
            /** Amounts owed to the supplier from purchases (increases payable) */
            $table->decimal('credit', 20, 4)->default(0);
            /** opening_balance | purchase_invoice | payment | … */
            $table->string('reference_type', 64)->index();
            $table->unsignedBigInteger('reference_id')->nullable()->index();
            $table->date('transaction_date')->index();
            $table->timestamps();

            $table->index(['supplier_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
