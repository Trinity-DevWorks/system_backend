<?php

namespace App\Modules\VatGroup\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable(['abrv', 'name', 'percentage', 'is_default'])]
class VatGroup extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }
}
