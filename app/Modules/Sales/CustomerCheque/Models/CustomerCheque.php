<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerCheque\Models;

use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Treasury\Models\CashBankAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cheque received from a customer. Its money sits in "Cheques to collect" until it clears into a bank
 * account, or bounces (its receipt is reversed and the invoice is open again).
 *
 * @property string $id
 * @property string $customer_receipt_id
 * @property string $customer_id
 * @property int|null $branch_id
 * @property string $cheque_number
 * @property string|null $bank_name
 * @property Carbon $due_date
 * @property string $amount
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $status
 * @property int|null $cleared_cash_bank_account_id
 * @property Carbon|null $cleared_on
 * @property Carbon|null $bounced_on
 */
#[Fillable([
    'customer_receipt_id',
    'customer_id',
    'branch_id',
    'cheque_number',
    'bank_name',
    'due_date',
    'amount',
    'currency_id',
    'exchange_rate',
    'status',
    'cleared_cash_bank_account_id',
    'cleared_on',
    'bounced_on',
    'updated_by',
])]
class CustomerCheque extends Model
{
    use HasUuids;

    public const REFERENCE_TYPE = 'customer_cheque';

    public const STATUS_IN_HAND = 'in_hand';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_BOUNCED = 'bounced';

    /** Its receipt was reversed before the cheque cleared (e.g. the invoice was reversed). */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'cleared_on' => 'date',
            'bounced_on' => 'date',
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:12',
        ];
    }

    /**
     * @return BelongsTo<CustomerReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function clearedCashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cleared_cash_bank_account_id');
    }
}
