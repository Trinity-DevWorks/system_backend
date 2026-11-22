<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('template_number', 32)->nullable()->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('document_type', 24);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('rate_mode', 16)->default('current');
            $table->decimal('fixed_exchange_rate', 24, 12)->nullable();
            $table->foreignId('cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->string('payee')->nullable();
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('reference', 128)->nullable();
            $table->text('notes')->nullable();
            $table->string('frequency', 16);
            $table->unsignedSmallInteger('interval')->default(1);
            $table->smallInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->unsignedInteger('occurrences_count')->default(0);
            $table->unsignedInteger('next_occurrence_index')->default(0);
            $table->date('next_run_date')->nullable();
            $table->date('last_run_date')->nullable();
            $table->boolean('auto_post')->default(false);
            $table->string('status', 16)->default('active');
            $table->string('last_error_code', 64)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_run_date']);
            $table->index('next_run_date');
        });

        DB::statement('ALTER TABLE recurring_templates ADD CONSTRAINT recurring_templates_interval_check CHECK (interval BETWEEN 1 AND 12)');
        DB::statement('ALTER TABLE recurring_templates ADD CONSTRAINT recurring_templates_day_of_month_check CHECK (day_of_month IS NULL OR day_of_month = -1 OR (day_of_month BETWEEN 1 AND 31))');
        DB::statement('ALTER TABLE recurring_templates ADD CONSTRAINT recurring_templates_end_date_check CHECK (end_date IS NULL OR end_date >= start_date)');

        Schema::create('recurring_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('recurring_template_id')->constrained('recurring_templates')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->decimal('amount', 20, 4)->default(0);
            $table->foreignId('vat_group_id')->nullable()->constrained('vat_groups')->nullOnDelete();
            $table->string('party_type', 16)->nullable();
            $table->uuid('party_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('recurring_template_id');
        });

        Schema::create('recurring_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('recurring_template_id')->constrained('recurring_templates')->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->string('status', 16);
            $table->string('document_type', 24);
            $table->uuid('document_id')->nullable();
            $table->string('document_number', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->foreignUuid('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamps();

            $table->unique(['recurring_template_id', 'scheduled_date']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignUuid('recurring_template_id')->nullable()->constrained('recurring_templates')->nullOnDelete();
            $table->index('recurring_template_id');
        });

        Schema::table('expense_vouchers', function (Blueprint $table) {
            $table->foreignUuid('recurring_template_id')->nullable()->constrained('recurring_templates')->nullOnDelete();
            $table->index('recurring_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('expense_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_template_id');
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_template_id');
        });
        Schema::dropIfExists('recurring_runs');
        Schema::dropIfExists('recurring_template_lines');
        Schema::dropIfExists('recurring_templates');
    }
};
