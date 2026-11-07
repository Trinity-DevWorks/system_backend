<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Models;

use App\Modules\Currency\Models\Currency;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Modules\Treasury\Models\TreasuryTransfer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment taken when the sales invoice is posted (split payment: cash, card, ...).
 * On post each line becomes a posted customer receipt allocated to the invoice.
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
 * @property string|null $treasury_transfer_id
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
    'treasury_transfer_id',
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
     * @return BelongsTo<TreasuryTransfer, $this>
     */
    public function treasuryTransfer(): BelongsTo
    {
        return $this->belongsTo(TreasuryTransfer::class);
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
