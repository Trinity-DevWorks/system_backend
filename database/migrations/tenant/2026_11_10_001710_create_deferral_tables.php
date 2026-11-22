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
        Schema::create('deferral_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schedule_number', 32)->nullable()->unique();
            $table->string('kind', 24);
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('deferral_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('recognition_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 24, 12);
            $table->decimal('total_amount', 20, 4);
            $table->decimal('total_amount_primary', 20, 4);
            $table->decimal('recognized_amount', 20, 4)->default(0);
            $table->decimal('recognized_amount_primary', 20, 4)->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('method', 24);
            $table->string('posting_day', 16)->default('period_end');
            $table->boolean('auto_post')->default(true);
            $table->string('source_type', 32)->default('manual');
            $table->uuid('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['source_type', 'source_id']);
            $table->index('branch_id');
        });

        DB::statement('CREATE UNIQUE INDEX deferral_schedules_source_line_unique ON deferral_schedules (source_type, source_id, source_line_id) WHERE source_line_id IS NOT NULL');
        DB::statement('ALTER TABLE deferral_schedules ADD CONSTRAINT deferral_schedules_dates_check CHECK (end_date >= start_date)');
        DB::statement('ALTER TABLE deferral_schedules ADD CONSTRAINT deferral_schedules_total_check CHECK (total_amount > 0)');

        Schema::create('deferral_schedule_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('deferral_schedule_id')->constrained('deferral_schedules')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('posting_date');
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_primary', 20, 4);
            $table->string('status', 16)->default('pending');
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('post_attempt')->default(0);
            $table->timestamps();

            $table->unique(['deferral_schedule_id', 'line_no']);
            $table->index(['status', 'posting_date']);
        });

        Schema::table('expense_voucher_lines', function (Blueprint $table) {
            $table->date('deferral_start_date')->nullable();
            $table->date('deferral_end_date')->nullable();
            $table->foreignId('deferral_recognition_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('deferral_method', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('expense_voucher_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deferral_recognition_account_id');
            $table->dropColumn(['deferral_start_date', 'deferral_end_date', 'deferral_method']);
        });
        Schema::dropIfExists('deferral_schedule_lines');
        Schema::dropIfExists('deferral_schedules');
    }
};
