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

        // Cash boxes / bank accounts a payment method uses (e.g. Cash -> Cash USD, Cash LBP). On a sale, the
        // invoice currency and branch pick one of them.
        Schema::create('payment_method_cash_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->foreignId('cash_bank_account_id')->constrained('cash_bank_accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['payment_method_id', 'cash_bank_account_id']);
        });

        // Receipts made by a sales invoice's payments at the moment of sale, and the cash/bank account they go to.
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->foreignId('cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->foreignUuid('sales_invoice_id')->nullable()->constrained('sales_invoices')->restrictOnDelete();
            $table->index('sales_invoice_id');
            // Paid by cheque: held in "Cheques to collect" until the cheque clears.
            $table->string('cheque_number', 64)->nullable();
            $table->string('cheque_bank', 128)->nullable();
            $table->date('cheque_due_date')->nullable();
        });

        // Credit left on a receipt can be applied to invoices later (customer credit).
        Schema::table('customer_receipt_allocations', function (Blueprint $table) {
            $table->boolean('applied_later')->default(false);
            $table->timestamp('applied_at')->nullable();
        });

        // Cash box / bank account the money leaves or enters (filled from the payment method when posted).
        foreach (['supplier_payments', 'customer_refunds', 'supplier_refunds'] as $documents) {
            Schema::table($documents, function (Blueprint $table) {
                $table->foreignId('cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->restrictOnDelete();
            });
        }

        // Payments taken when a sales invoice is posted (split payment: cash, card, ...).
        Schema::create('sales_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            // payment = money taken now; credit = the customer's existing credit applied to this invoice.
            $table->string('kind', 16)->default('payment');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            // Chosen in the payment modal, else filled from the payment method when the invoice is posted.
            $table->foreignId('cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->restrictOnDelete();
            // A payment may be in another currency (e.g. a USD invoice paid partly in LBP).
            // Null currency = the invoice currency. Rate: 1 primary = rate × that currency.
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12)->nullable();
            $table->decimal('amount', 20, 4);
            $table->string('reference', 128)->nullable();
            // Cash given by the customer and the change handed back (printed only, not posted).
            $table->decimal('tendered', 20, 4)->nullable();
            $table->decimal('change_due', 20, 4)->nullable();
            // Change handed back in another currency (e.g. paid in USD, change in LBP): posted as a cash
            // transfer from the change cash box into the paid one.
            $table->foreignId('change_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->decimal('change_rate', 24, 12)->nullable();
            $table->decimal('change_amount', 20, 4)->nullable();
            $table->foreignId('change_cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->foreignUuid('treasury_transfer_id')->nullable()->constrained('treasury_transfers')->nullOnDelete();
            $table->string('cheque_number', 64)->nullable();
            $table->string('cheque_bank', 128)->nullable();
            $table->date('cheque_due_date')->nullable();
            $table->foreignUuid('customer_receipt_id')->nullable()->constrained('customer_receipts')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('sales_invoice_id');
        });

        // Cheques received from customers, held in "Cheques to collect" until they clear or bounce.
        Schema::create('customer_cheques', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_receipt_id')->constrained('customer_receipts')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('cheque_number', 64);
            $table->string('bank_name', 128)->nullable();
            $table->date('due_date');
            $table->decimal('amount', 20, 4);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12);
            // in_hand | cleared | bounced
            $table->string('status', 16)->default('in_hand');
            $table->foreignId('cleared_cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->date('cleared_on')->nullable();
            $table->date('bounced_on')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_cheques');
        Schema::dropIfExists('sales_invoice_payments');
        Schema::table('customer_receipt_allocations', function (Blueprint $table) {
            $table->dropColumn(['applied_later', 'applied_at']);
        });
        foreach (['supplier_payments', 'customer_refunds', 'supplier_refunds'] as $documents) {
            Schema::table($documents, function (Blueprint $table) {
                $table->dropConstrainedForeignId('cash_bank_account_id');
            });
        }
        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->dropColumn(['cheque_number', 'cheque_bank', 'cheque_due_date']);
            $table->dropConstrainedForeignId('sales_invoice_id');
            $table->dropConstrainedForeignId('cash_bank_account_id');
        });
        Schema::dropIfExists('payment_method_cash_bank_accounts');
        Schema::dropIfExists('expense_voucher_lines');
        Schema::dropIfExists('expense_vouchers');
        Schema::dropIfExists('treasury_transfers');
        Schema::dropIfExists('cash_bank_accounts');
    }
};
