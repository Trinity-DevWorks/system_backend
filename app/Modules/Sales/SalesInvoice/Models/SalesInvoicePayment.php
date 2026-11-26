<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Models;

use App\Modules\Currency\Models\Currency;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Treasury\Models\CashBankAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment on a sales invoice (split payment: cash, card, cheque, customer credit, ...).
 * Taken at the sale (`at_sale`): posted in the invoice's own journal entry, no receipt.
 * Recorded later: becomes a posted customer receipt (`customer_receipt_id`).
 *
 * @property int $id
 * @property string $sales_invoice_id
 * @property int $payment_method_id
 * @property int|null $cash_bank_account_id
 * @property int|null $currency_id null = the invoice currency
 * @property string|null $exchange_rate 1 primary = rate × the payment currency
 * @property string $amount
 * @property string|null $reference
 * @property string|null $tendered
 * @property string|null $change_due
 * @property string|null $customer_receipt_id
 * @property string $kind payment | credit (the customer's existing credit applied to the invoice)
 * @property int|null $change_currency_id change handed back in another currency
 * @property string|null $change_rate
 * @property string|null $change_amount in the change currency
 * @property int|null $change_cash_bank_account_id
 * @property bool $at_sale
 * @property string|null $applied_amount value settled on the invoice, in the invoice currency
 * @property string|null $extra_credit part kept as customer credit, in the invoice currency
 * @property Carbon|null $bounced_on the cheque of this payment bounced
 * @property string|null $cheque_number
 * @property string|null $cheque_bank
 * @property Carbon|null $cheque_due_date
 */
#[Fillable([
    'sales_invoice_id',
    'kind',
    'payment_method_id',
    'cash_bank_account_id',
    'currency_id',
    'exchange_rate',
    'amount',
    'reference',
    'tendered',
    'change_due',
    'change_currency_id',
    'change_rate',
    'change_amount',
    'change_cash_bank_account_id',
    'at_sale',
    'applied_amount',
    'extra_credit',
    'bounced_on',
    'cheque_number',
    'cheque_bank',
    'cheque_due_date',
    'customer_receipt_id',
    'sort_order',
])]
class SalesInvoicePayment extends Model
{
    public const KIND_PAYMENT = 'payment';

    public const KIND_CREDIT = 'credit';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'tendered' => 'decimal:4',
            'change_due' => 'decimal:4',
            'exchange_rate' => 'decimal:12',
            'change_rate' => 'decimal:12',
            'change_amount' => 'decimal:4',
            'cheque_due_date' => 'date',
            'at_sale' => 'boolean',
            'applied_amount' => 'decimal:4',
            'extra_credit' => 'decimal:4',
            'bounced_on' => 'date',
        ];
    }

    public function isCredit(): bool
    {
        return $this->kind === self::KIND_CREDIT;
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function changeCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'change_currency_id');
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function changeCashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'change_cash_bank_account_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<CustomerReceipt, $this>
     */
    public function customerReceipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class);
    }
}
