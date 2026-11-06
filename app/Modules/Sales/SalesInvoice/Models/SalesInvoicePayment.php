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
 */
#[Fillable([
    'sales_invoice_id',
    'payment_method_id',
    'cash_bank_account_id',
    'currency_id',
    'exchange_rate',
    'amount',
    'reference',
    'tendered',
    'change_due',
    'customer_receipt_id',
    'sort_order',
])]
class SalesInvoicePayment extends Model
{
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
        ];
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
