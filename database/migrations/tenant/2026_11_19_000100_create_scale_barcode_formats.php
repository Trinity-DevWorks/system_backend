<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scale (weighed / priced item) barcode formats — plans/scale-barcodes.md.
 * Nothing about a scale brand, prefix, position, precision or currency is hardcoded: each label
 * layout is one row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scale_barcode_formats', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // fixed = positions in the string (EAN-13, UPC-A, custom Code 128); gs1 = Application Identifiers.
            $table->string('engine', 16);
            $table->string('symbology', 24);
            $table->json('prefixes')->nullable();
            $table->unsignedSmallInteger('total_length')->nullable();
            // 1-based positions (fixed engine only).
            $table->unsignedSmallInteger('plu_start')->nullable();
            $table->unsignedSmallInteger('plu_length')->nullable();
            $table->unsignedSmallInteger('value_start')->nullable();
            $table->unsignedSmallInteger('value_length')->nullable();
            $table->string('value_type', 16); // weight | price | count
            $table->unsignedTinyInteger('decimals')->default(0);
            $table->decimal('multiplier', 20, 6)->default(1);
            $table->foreignId('uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->boolean('price_includes_tax')->default(true);
            $table->boolean('verify_check_digit')->default(true);
            $table->unsignedSmallInteger('price_check_digit_position')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });

        Schema::create('scale_barcode_format_branch', function (Blueprint $table) {
            $table->foreignId('scale_barcode_format_id')->constrained('scale_barcode_formats')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->primary(['scale_barcode_format_id', 'branch_id']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_scale_item')->default(false)->after('track_serials');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('is_scale_item');
        });
        Schema::dropIfExists('scale_barcode_format_branch');
        Schema::dropIfExists('scale_barcode_formats');
    }
};
