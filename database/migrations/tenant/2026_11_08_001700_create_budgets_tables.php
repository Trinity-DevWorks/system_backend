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
        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->date('fiscal_year_start');
            $table->date('fiscal_year_end');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('parent_budget_id')->nullable();
            $table->string('status', 16)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'fiscal_year_start']);
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->foreign('parent_budget_id')->references('id')->on('budgets')->nullOnDelete();
        });

        // One approved budget per fiscal year and branch. A company-wide budget uses branch 0.
        DB::statement(
            "CREATE UNIQUE INDEX budgets_one_approved_per_year_branch ON budgets (fiscal_year_start, (COALESCE(branch_id, 0))) WHERE status = 'approved'"
        );

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('period_month');
            $table->decimal('amount', 20, 4);
            $table->timestamps();

            $table->unique(['budget_id', 'account_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
