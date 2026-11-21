<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            /** Primary currency. One side is always 0. */
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12)->default(1);
            /** Document-currency amount, signed: positive = debit. */
            $table->decimal('amount_currency', 20, 4)->default(0);
            /** customer | supplier */
            $table->string('party_type', 16)->nullable();
            $table->uuid('party_id')->nullable();
            $table->string('description', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['account_id', 'journal_entry_id']);
            $table->index(['party_type', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
