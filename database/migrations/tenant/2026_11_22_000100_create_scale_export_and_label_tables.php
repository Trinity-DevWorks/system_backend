<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scale barcodes phase 2 (plans/scale-barcodes-phase-2.md): export files for scale software,
 * the export history, label templates, and item shelf life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scale_export_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // [{field, header, width?, pad_char?, pad_side?, constant?}]
            $table->json('columns');
            $table->string('delimiter', 8)->default(','); // , ; tab | fixed
            $table->boolean('has_header')->default(true);
            $table->string('encoding', 16)->default('utf8'); // utf8 | utf8_bom | windows1256
            $table->string('line_ending', 4)->default('crlf'); // crlf | lf
            $table->string('quote', 8)->default('needed'); // always | needed | never
            $table->string('file_extension', 8)->default('csv'); // csv | txt | xlsx
            $table->string('decimal_separator', 1)->default('.');
            $table->unsignedTinyInteger('price_decimals')->default(2);
            $table->decimal('price_multiplier', 20, 6)->default(1);
            $table->unsignedTinyInteger('weight_decimals')->default(3);
            $table->string('price_source', 16)->default('item_price'); // item_price | price_list
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->json('category_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('scale_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scale_export_template_id')->constrained('scale_export_templates')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->boolean('only_changed')->default(false);
            $table->unsignedInteger('item_count');
            $table->json('item_ids');
            $table->string('file_name', 200);
            $table->foreignUuid('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['scale_export_template_id', 'created_at']);
        });

        Schema::create('scale_label_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('mode', 8)->default('single'); // single | sheet
            $table->decimal('width_mm', 6, 1)->default(60);
            $table->decimal('height_mm', 6, 1)->default(40);
            $table->unsignedTinyInteger('sheet_columns')->default(3);
            $table->unsignedTinyInteger('sheet_rows')->default(8);
            $table->decimal('margin_mm', 5, 1)->default(8);
            $table->decimal('gap_mm', 5, 1)->default(2);
            // which parts are printed: company, name, second_name, unit_price, value, total,
            // packed_date, use_by, barcode, barcode_text, plu
            $table->json('fields');
            $table->unsignedTinyInteger('font_size')->default(9);
            $table->foreignId('scale_barcode_format_id')->nullable()->constrained('scale_barcode_formats')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->unsignedSmallInteger('shelf_life_days')->nullable()->after('is_scale_item');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('shelf_life_days');
        });
        Schema::dropIfExists('scale_label_templates');
        Schema::dropIfExists('scale_exports');
        Schema::dropIfExists('scale_export_templates');
    }
};
