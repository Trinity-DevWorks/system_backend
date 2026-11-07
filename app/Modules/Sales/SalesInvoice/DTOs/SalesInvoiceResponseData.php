<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\DTOs;

use App\Models\User;
use App\Modules\Branch\Support\DocumentBranchPayload;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Warehouse\Models\Warehouse;

readonly class SalesInvoiceResponseData
{
    public static function fromModel(SalesInvoice $invoice, bool $includeLines = true): array
    {
        $invoice->loadMissing([
            'customer:id,customer_code,name,phone,status,is_system,salesman_id,payment_method_id,payment_terms_id',
            'warehouse:id,name,shortcut_name,is_active',
            'currency:id,code,name,symbol',
            'salesman:id,full_name,salesman_code',
            'paymentMethod:id,name,code',
            'paymentTerm:id,name,code,due_days',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
            'salesOrder:id,order_number,status',
        ]);

        $payload = [
            'id' => $invoice->id,
            ...DocumentBranchPayload::of($invoice),
            'invoice_number' => $invoice->invoice_number,
            'is_opening' => (bool) $invoice->is_opening,
            'original_number' => $invoice->original_number,
            'customer_id' => $invoice->customer_id,
            'sales_order_id' => $invoice->sales_order_id,
            'warehouse_id' => $invoice->warehouse_id,
            'currency_id' => $invoice->currency_id,
            'salesman_id' => $invoice->salesman_id,
            'payment_method_id' => $invoice->payment_method_id,
            'payment_terms_id' => $invoice->payment_terms_id,
            'status' => $invoice->status instanceof SalesInvoiceStatus
                ? $invoice->status->value
                : (string) $invoice->status,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'exchange_rate' => (string) $invoice->exchange_rate,
            'reference_2' => $invoice->reference_2,
            'billing_address' => $invoice->billing_address,
            'shipping_address' => $invoice->shipping_address,
            'subtotal' => (string) $invoice->subtotal,
            'discount_total' => (string) $invoice->discount_total,
            'header_discount_type' => $invoice->header_discount_type,
            'header_discount_value' => (string) $invoice->header_discount_value,
            'discount_approved_by' => $invoice->discount_approved_by,
            'discount_approved_at' => $invoice->discount_approved_at?->toIso8601String(),
            'tax_total' => (string) $invoice->tax_total,
            'adjustment' => (string) $invoice->adjustment,
            'grand_total' => (string) $invoice->grand_total,
            'paid_total' => (string) $invoice->paid_total,
            'credited_total' => (string) $invoice->credited_total,
            'creditable_total' => self::creditableTotal($invoice),
            'net_to_pay' => (string) $invoice->net_to_pay,
            'notes' => $invoice->notes,
            'customer' => self::customerBrief($invoice->customer),
            'sales_order' => self::orderBrief($invoice->salesOrder),
            'warehouse' => self::warehouseBrief($invoice->warehouse),
            'currency' => self::currencyBrief($invoice->currency),
            'salesman' => self::salesmanBrief($invoice->salesman),
            'payment_method' => self::paymentMethodBrief($invoice->paymentMethod),
            'payment_term' => self::paymentTermBrief($invoice->paymentTerm),
            'created_by' => self::userBrief($invoice->createdByUser),
            'posted_by' => self::userBrief($invoice->postedByUser),
            'posted_at' => $invoice->posted_at?->toIso8601String(),
            'lines_count' => $invoice->lines_count ?? null,
            'created_at' => (string) $invoice->created_at,
            'updated_at' => (string) $invoice->updated_at,
        ];

        if ($includeLines) {
            $invoice->loadMissing([
                'lines.item.itemType',
                'lines.itemUom.uom',
                'lines.warehouse',
                'lines.lot',
                'lines.salesOrderLine',
            ]);
            $payload['lines'] = SalesInvoiceLineResponseData::collectionToArray($invoice->lines);

            // Payments taken at the moment of sale (split payment).
            $invoice->loadMissing([
                'payments.paymentMethod:id,code,name,type,supports_change',
                'payments.cashBankAccount:id,code,name',
                'payments.currency:id,code,symbol',
                'payments.changeCurrency:id,code,symbol',
                'payments.customerReceipt:id,receipt_number,status',
            ]);
            $payload['payments'] = $invoice->payments->map(fn ($payment): array => [
                'id' => $payment->id,
                'payment_method_id' => (int) $payment->payment_method_id,
                'payment_method_name' => $payment->paymentMethod?->name,
                'payment_method_type' => $payment->paymentMethod?->type?->value,
                'supports_change' => (bool) $payment->paymentMethod?->supports_change,
                'cash_bank_account_id' => $payment->cash_bank_account_id !== null ? (int) $payment->cash_bank_account_id : null,
                'cash_bank_account_name' => $payment->cashBankAccount?->name,
                'currency_id' => $payment->currency_id !== null ? (int) $payment->currency_id : (int) $invoice->currency_id,
                'currency_code' => $payment->currency?->code,
                'exchange_rate' => $payment->exchange_rate !== null ? (string) $payment->exchange_rate : null,
                'amount' => (string) $payment->amount,
                'reference' => $payment->reference,
                'tendered' => $payment->tendered !== null ? (string) $payment->tendered : null,
                'change_due' => $payment->change_due !== null ? (string) $payment->change_due : null,
                'kind' => $payment->kind ?? 'payment',
                'change_currency_id' => $payment->change_currency_id !== null ? (int) $payment->change_currency_id : null,
                'change_currency_code' => $payment->changeCurrency?->code,
                'change_amount' => $payment->change_amount !== null ? (string) $payment->change_amount : null,
                'change_cash_bank_account_id' => $payment->change_cash_bank_account_id !== null ? (int) $payment->change_cash_bank_account_id : null,
                'treasury_transfer_id' => $payment->treasury_transfer_id,
                'cheque_number' => $payment->cheque_number,
                'cheque_bank' => $payment->cheque_bank,
                'cheque_due_date' => $payment->cheque_due_date?->toDateString(),
                'customer_receipt_id' => $payment->customer_receipt_id,
                'receipt_number' => $payment->customerReceipt?->receipt_number,
            ])->values()->all();
        }

        return $payload;
    }

    /**
     * Invoice total still available for another posted credit note.
     * credited_total is only the amount applied to the balance, so a fully paid
     * invoice can already be fully credited while that field stays at zero.
     */
    private static function creditableTotal(SalesInvoice $invoice): string
    {
        $posted = $invoice->getAttribute('posted_credit_total');
        $used = $posted === null ? '0' : (string) $posted;
        $remaining = bcsub((string) $invoice->grand_total, $used, 4);

        return bccomp($remaining, '0', 4) < 0 ? '0.0000' : $remaining;
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @return array{id:string,order_number:?string,status:string}|null
     */
    private static function orderBrief(?SalesOrder $order): ?array
    {
        if (! $order) {
            return null;
        }

        $status = $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status;

        return [
            'id' => (string) $order->id,
            'order_number' => $order->order_number,
            'status' => $status,
        ];
    }

    private static function customerBrief(?Customer $customer): ?array
    {
        if (! $customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'status' => $customer->status instanceof \BackedEnum ? $customer->status->value : (string) $customer->status,
            'is_system' => (bool) $customer->is_system,
        ];
    }

    /**
     * @return array{id:int,name:string,shortcut_name:?string,is_active:bool}|null
     */
    private static function warehouseBrief(?Warehouse $warehouse): ?array
    {
        if (! $warehouse) {
            return null;
        }

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'shortcut_name' => $warehouse->shortcut_name,
            'is_active' => (bool) $warehouse->is_active,
        ];
    }

    /**
     * @return array{id:int,code:string,name:string,symbol:?string}|null
     */
    private static function currencyBrief(?Currency $currency): ?array
    {
        if (! $currency) {
            return null;
        }

        return [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
        ];
    }

    /**
     * @return array{id:string,name:?string,code:?string}|null
     */
    private static function salesmanBrief(?Salesman $salesman): ?array
    {
        if (! $salesman) {
            return null;
        }

        return [
            'id' => $salesman->id,
            'name' => $salesman->full_name ?? null,
            'code' => $salesman->salesman_code ?? null,
        ];
    }

    /**
     * @return array{id:int,name:?string,code:?string}|null
     */
    private static function paymentMethodBrief(?PaymentMethod $method): ?array
    {
        if (! $method) {
            return null;
        }

        return [
            'id' => $method->id,
            'name' => $method->name ?? null,
            'code' => $method->code ?? null,
        ];
    }

    /**
     * @return array{id:int,name:?string,code:?string,due_days:int}|null
     */
    private static function paymentTermBrief(?PaymentTerm $term): ?array
    {
        if (! $term) {
            return null;
        }

        return [
            'id' => $term->id,
            'name' => $term->name ?? null,
            'code' => $term->code ?? null,
            'due_days' => (int) $term->due_days,
        ];
    }

    /**
     * @return array{id:string,name:string,email:?string}|null
     */
    private static function userBrief(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
