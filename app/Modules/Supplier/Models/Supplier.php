<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Models;

use App\Models\Attachment;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\VatGroup\Models\VatGroup;
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
    'payment_method_id',
    'payment_terms_id',
    'vat_group_id',
    'supplier_code',
    'name',
    'company_name',
    'email',
    'phone',
    'is_active',
    'is_vat_registered',
    'is_exempted',
    'exemption_reason',
    'exempted_from',
    'exempted_to',
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
            'is_active' => 'boolean',
            'is_vat_registered' => 'boolean',
            'is_exempted' => 'boolean',
            'exempted_from' => 'date',
            'exempted_to' => 'date',
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
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_terms_id');
    }

    /**
     * @return BelongsTo<VatGroup, $this>
     */
    public function vatGroup(): BelongsTo
    {
        return $this->belongsTo(VatGroup::class);
    }

    /**
     * @return HasMany<SupplierBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(SupplierBalance::class);
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
     * @return HasMany<SupplierItem, $this>
     */
    public function supplierItems(): HasMany
    {
        return $this->hasMany(SupplierItem::class);
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
