<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use App\Models\Attachment;
use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Enums\CustomerType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'customer_group_id',
    'salesman_id',
    'payment_method_id',
    'payment_terms_id',
    'vat_group_id',
    'customer_code',
    'name',
    'email',
    'phone',
    'type',
    'status',
    'blacklist_reason',
    'is_vat_registered',
    'is_exempted',
    'exemption_reason',
    'exempted_from',
    'exempted_to',
    'vat_number',
    'notes',
])]
class Customer extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
            'is_vat_registered' => 'boolean',
            'is_exempted' => 'boolean',
            'exempted_from' => 'date',
            'exempted_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /**
     * @return BelongsTo<Salesman, $this>
     */
    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
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
     * @return HasMany<CustomerBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(CustomerBalance::class);
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * @return HasMany<CustomerContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /**
     * @return HasMany<CustomerLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
