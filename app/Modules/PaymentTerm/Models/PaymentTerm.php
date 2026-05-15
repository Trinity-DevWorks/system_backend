<?php

declare(strict_types=1);

namespace App\Modules\PaymentTerm\Models;

use Database\Factories\PaymentTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'code',
    'name',
    'due_days',
    'description',
    'is_default',
    'is_active',
])]
class PaymentTerm extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PaymentTermFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_days' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
