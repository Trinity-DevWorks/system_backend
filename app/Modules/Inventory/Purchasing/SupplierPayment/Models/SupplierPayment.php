<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierPayment\Models;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Purchasing\SupplierPayment\Enums\SupplierPaymentStatus;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property SupplierPaymentStatus $status
 * @property Carbon|null $payment_date
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'payment_number',
    'supplier_id',
    'currency_id',
    'exchange_rate',
    'payment_method_id',
    'payment_date',
    'amount',
    'reference',
    'notes',
    'status',
    'created_by',
    'posted_by',
    'posted_at',
])]
class SupplierPayment extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierPaymentStatus::class,
            'payment_date' => 'date',
            'exchange_rate' => 'decimal:12',
            'amount' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return HasMany<SupplierPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class)->orderBy('id');
    }
}
