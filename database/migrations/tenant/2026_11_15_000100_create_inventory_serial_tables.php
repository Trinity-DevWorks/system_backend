<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-unit serial numbers for stock items (plans/serial-numbers.md).
 * A serial is identity + history; costing stays per item / warehouse.
 */
return new class extends Migration
{
    /** Line tables whose drafts keep the entered serials until posting. */
    private const LINE_TABLES = [
        'goods_receipt_lines',
        'opening_stock_lines',
        'sales_invoice_lines',
        'delivery_note_lines',
        'sales_credit_note_lines',
    ];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('track_serials')->default(false)->after('track_lots');
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('track_serials');
        });

        Schema::create('inventory_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->string('serial_number', 100);
            // in_stock | in_transit | sold | returned_to_supplier | consumed | scrapped | void
            $table->string('status', 32);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->string('received_ref_type', 64)->nullable();
            $table->string('received_ref_id', 64)->nullable();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->string('sold_ref_type', 64)->nullable();
            $table->string('sold_ref_id', 64)->nullable();
            $table->date('warranty_ends_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'serial_number']);
            $table->index(['item_id', 'status', 'warehouse_id']);
            $table->index('serial_number');
            $table->index('customer_id');
        });

        Schema::create('inventory_serial_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_serial_id')->constrained('inventory_serials')->cascadeOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->string('reference_type', 64)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('movement_type', 32);
            $table->string('direction', 8); // in | out
            $table->boolean('is_reversal')->default(false);
            // Set on the original row once its document is reversed.
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            // State before this move, so a reversal can put the unit back exactly.
            $table->string('status_before', 32)->nullable();
            $table->unsignedBigInteger('warehouse_before_id')->nullable();
            $table->string('status_after', 32);
            $table->string('party_type', 16)->nullable(); // customer | supplier
            $table->string('party_id', 64)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->index(['inventory_serial_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });

        foreach (self::LINE_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('serial_numbers')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::LINE_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('serial_numbers');
            });
        }
        Schema::dropIfExists('inventory_serial_movements');
        Schema::dropIfExists('inventory_serials');
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['track_serials', 'warranty_months']);
        });
    }
};
