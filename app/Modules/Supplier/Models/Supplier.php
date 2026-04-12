<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Models;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'supplier_group_id',
    'supplier_code',
    'name',
    'email',
    'phone',
    'credit_limit',
    'opening_balance',
    'is_active',
    'is_vat_registered',
    'vat_number',
    'notes',
])]
class Supplier extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'opening_balance' => 'decimal:4',
            'is_active' => 'boolean',
            'is_vat_registered' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SupplierGroup, $this>
     */
    public function supplierGroup(): BelongsTo
    {
        return $this->belongsTo(SupplierGroup::class, 'supplier_group_id');
    }

    /**
     * @return HasMany<SupplierAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(SupplierAddress::class);
    }

    /**
     * @return HasMany<SupplierContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    /**
     * @return HasMany<SupplierLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
