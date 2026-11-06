<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Service items such as "Freight" or "Customs duty" that feed landed costs; weight/volume per base unit drive allocation.
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_landed_cost')->default(false)->after('allow_return');
            $table->decimal('weight', 14, 6)->nullable()->after('is_landed_cost');
            $table->decimal('volume', 14, 6)->nullable()->after('weight');
        });

        // One shipment's extra costs (freight, customs, agent) spread over the goods it brought in.
        Schema::create('landed_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('branch_id')->index()->constrained('branches')->restrictOnDelete();
            $table->string('landed_cost_number', 32)->nullable()->unique();
            $table->date('cost_date');
            $table->string('reference', 128)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'cost_date']);
        });

        // Receipts the costs are spread over: posted goods receipts or direct (stock-moving) purchase invoices.
        Schema::create('landed_cost_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('landed_cost_id')->constrained('landed_costs')->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->uuid('source_id');
            $table->timestamps();

            $table->unique(['landed_cost_id', 'source_type', 'source_id']);
            $table->index(['source_type', 'source_id']);
        });

        // Charges taken from bills: purchase invoice lines (landed cost items) or expense voucher lines (clearing account).
        Schema::create('landed_cost_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('landed_cost_id')->constrained('landed_costs')->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_line_id');
            $table->decimal('amount', 20, 4);
            $table->string('method', 16)->default('value');
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['source_type', 'source_line_id']);
        });

        // Each charge's share per receipt line, and how much went to stock vs cost of sales at posting.
        Schema::create('landed_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landed_cost_charge_id')->constrained('landed_cost_charges')->cascadeOnDelete();
            $table->string('receipt_source_type', 32);
            $table->unsignedBigInteger('receipt_line_id');
            $table->foreignId('source_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('received_quantity', 14, 6);
            $table->decimal('basis', 24, 6)->default(0);
            $table->decimal('amount', 20, 4);
            $table->decimal('manual_amount', 20, 4)->nullable();
            $table->decimal('on_hand_basis', 14, 6)->default(0);
            $table->decimal('inventory_amount', 20, 4)->default(0);
            $table->decimal('cogs_amount', 20, 4)->default(0);
            $table->decimal('variance_amount', 20, 4)->default(0);
            $table->timestamps();

            $table->index('landed_cost_charge_id');
            $table->index(['receipt_source_type', 'receipt_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
        Schema::dropIfExists('landed_cost_charges');
        Schema::dropIfExists('landed_cost_receipts');
        Schema::dropIfExists('landed_costs');
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['is_landed_cost', 'weight', 'volume']);
        });
    }
};
