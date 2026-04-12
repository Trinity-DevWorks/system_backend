<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use App\Models\Attachment;
use App\Modules\Customer\Enums\CustomerType;
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
    'customer_group_id',
    'customer_code',
    'name',
    'email',
    'phone',
    'type',
    'credit_limit',
    'opening_balance',
    'is_active',
    'is_vat_registered',
    'vat_number',
    'notes',
])]
class Customer extends Model implements AuditableContract
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
            'type' => CustomerType::class,
            'credit_limit' => 'decimal:4',
            'opening_balance' => 'decimal:4',
            'is_active' => 'boolean',
            'is_vat_registered' => 'boolean',
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
