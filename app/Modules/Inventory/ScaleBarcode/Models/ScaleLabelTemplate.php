<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Layout of a weighed-item label printed by the ERP (label printer roll or A4 sheet).
 *
 * @property int $id
 * @property string $name
 * @property string $mode single | sheet
 * @property string $width_mm
 * @property string $height_mm
 * @property int $sheet_columns
 * @property int $sheet_rows
 * @property string $margin_mm
 * @property string $gap_mm
 * @property list<string> $fields
 * @property int $font_size
 * @property int|null $scale_barcode_format_id
 * @property bool $is_active
 */
#[Fillable([
    'name', 'mode', 'width_mm', 'height_mm', 'sheet_columns', 'sheet_rows', 'margin_mm', 'gap_mm',
    'fields', 'font_size', 'scale_barcode_format_id', 'is_active',
])]
class ScaleLabelTemplate extends Model implements AuditableContract
{
    use Auditable;

    public const MODES = ['single', 'sheet'];

    public const FIELDS = [
        'company', 'name', 'second_name', 'unit_price', 'value', 'total',
        'packed_date', 'use_by', 'barcode', 'barcode_text', 'plu',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'width_mm' => 'decimal:1',
            'height_mm' => 'decimal:1',
            'margin_mm' => 'decimal:1',
            'gap_mm' => 'decimal:1',
            'sheet_columns' => 'integer',
            'sheet_rows' => 'integer',
            'font_size' => 'integer',
            'scale_barcode_format_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ScaleBarcodeFormat, $this>
     */
    public function barcodeFormat(): BelongsTo
    {
        return $this->belongsTo(ScaleBarcodeFormat::class, 'scale_barcode_format_id');
    }
}
