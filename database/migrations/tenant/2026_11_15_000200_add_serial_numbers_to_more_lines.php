<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serial numbers on the remaining stock documents (plans/serial-numbers.md, S2.3).
 * `productions.serial_numbers` holds the produced units; lines hold issued / received units.
 */
return new class extends Migration
{
    private const TABLES = [
        'stock_adjustment_lines',
        'stock_count_lines',
        'stock_transfer_lines',
        'stock_transfer_receipt_lines',
        'stock_transfer_closure_lines',
        'productions',
        'production_lines',
        'bundle_explosion_lines',
        'purchase_invoice_lines',
        'purchase_debit_note_lines',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('serial_numbers')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('serial_numbers');
            });
        }
    }
};
