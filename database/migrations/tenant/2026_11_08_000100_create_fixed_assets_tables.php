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
        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('disposal_gain_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('disposal_loss_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('method', 32);
            $table->unsignedInteger('useful_life_months');
            $table->decimal('declining_rate', 8, 4)->nullable();
            $table->decimal('salvage_percent', 8, 4)->default(0);
            $table->boolean('prorate_first_month')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('asset_number', 32)->nullable()->unique();
            $table->string('name');
            $table->foreignId('category_id')->constrained('fixed_asset_categories')->restrictOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('source_type', 32);
            $table->string('source_id', 64)->nullable();
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('serial_number', 128)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('custodian', 255)->nullable();
            $table->date('acquisition_date');
            $table->date('in_service_date');
            $table->decimal('cost', 20, 4);
            $table->decimal('salvage_value', 20, 4)->default(0);
            $table->string('method', 32);
            $table->unsignedInteger('useful_life_months');
            $table->decimal('declining_rate', 8, 4)->nullable();
            $table->boolean('prorate_first_month')->default(false);
            $table->decimal('opening_accumulated', 20, 4)->default(0);
            $table->decimal('accumulated_depreciation', 20, 4)->default(0);
            $table->date('depreciated_through')->nullable();
            $table->string('status', 32)->default('draft');
            $table->date('disposed_at')->nullable();
            $table->decimal('disposal_amount', 20, 4)->nullable();
            $table->decimal('disposal_catch_up', 20, 4)->default(0);
            $table->date('disposal_prior_through')->nullable();
            $table->string('disposal_number', 32)->nullable();
            $table->foreignUuid('disposal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'depreciated_through']);
            $table->index(['source_type', 'source_id']);
            $table->index('category_id');
        });

        Schema::create('fixed_asset_depreciation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // One run per branch and month: each branch posts and undoes its own depreciation.
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('period_month');
            $table->string('run_number', 32)->nullable();
            $table->string('status', 16)->default('posted');
            $table->decimal('total', 20, 4)->default(0);
            $table->boolean('is_automatic')->default(false);
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'period_month']);
            $table->index(['branch_id', 'period_month']);
        });
        // At most one posted run per branch and month (undone runs stay as history).
        DB::statement("CREATE UNIQUE INDEX fixed_asset_depreciation_runs_posted_unique ON fixed_asset_depreciation_runs (branch_id, period_month) WHERE status = 'posted'");

        Schema::create('fixed_asset_depreciation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('fixed_asset_depreciation_runs')->cascadeOnDelete();
            $table->foreignUuid('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->decimal('book_value_after', 20, 4);
            $table->date('through_before')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'fixed_asset_id']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_fixed_asset')->default(false)->after('is_landed_cost');
            $table->foreignId('fixed_asset_category_id')->nullable()->after('is_fixed_asset')->constrained('fixed_asset_categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fixed_asset_category_id');
            $table->dropColumn('is_fixed_asset');
        });

        Schema::dropIfExists('fixed_asset_depreciation_lines');
        Schema::dropIfExists('fixed_asset_depreciation_runs');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');
    }
};
