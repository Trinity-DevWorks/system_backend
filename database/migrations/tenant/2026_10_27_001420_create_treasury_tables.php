<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury: cash/bank accounts (a register over GL asset accounts), transfers between them,
 * and expense vouchers paid from them. Documents follow draft -> posted -> reversed and post
 * journals through JournalPostingService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('type', 16); // cash | bank
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('iban', 64)->nullable();
            $table->string('account_number', 64)->nullable();
            // Money already in the account when it is added; posted as an opening journal entry.
            $table->decimal('opening_balance', 20, 4)->default(0);
            $table->date('opening_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('account_id');
        });

        Schema::create('treasury_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('branch_id')->index()->constrained('branches')->restrictOnDelete();
            $table->string('transfer_number', 32)->nullable()->unique(); // assigned at posting
            $table->date('transfer_date');
            $table->foreignId('from_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->decimal('amount_from', 20, 4);
            $table->decimal('amount_to', 20, 4);
            // 1 primary = rate x account currency (same convention as other documents).
            $table->decimal('exchange_rate', 24, 12)->default(1);
            $table->decimal('exchange_rate_to', 24, 12)->default(1);
            $table->string('reference', 128)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'transfer_date']);
        });

        Schema::create('expense_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('branch_id')->index()->constrained('branches')->restrictOnDelete();
            $table->string('voucher_number', 32)->nullable()->unique(); // assigned at posting
            $table->date('voucher_date');
            $table->foreignId('cash_bank_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12)->default(1);
            $table->string('payee')->nullable();
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('reference', 128)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('total', 20, 4)->default(0);
            $table->string('status', 16)->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'voucher_date']);
        });

        Schema::create('expense_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('expense_voucher_id')->constrained('expense_vouchers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->decimal('amount', 20, 4); // net of VAT
            $table->foreignId('vat_group_id')->nullable()->constrained('vat_groups')->nullOnDelete();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('expense_voucher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_voucher_lines');
        Schema::dropIfExists('expense_vouchers');
        Schema::dropIfExists('treasury_transfers');
        Schema::dropIfExists('cash_bank_accounts');
    }
};
