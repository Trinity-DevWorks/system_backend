<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerReceipt\DTOs;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Sales\CustomerReceipt\Enums\CustomerReceiptStatus;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceiptAllocation;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use Illuminate\Support\Collection;

readonly class CustomerReceiptResponseData
{
    public static function fromModel(CustomerReceipt $receipt, bool $includeAllocations = true): array
    {
        $receipt->loadMissing([
            'customer:id,customer_code,name,phone,status,is_system',
            'currency:id,code,name,symbol',
            'paymentMethod:id,name,code,type,requires_reference,is_active',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'customer_id' => $receipt->customer_id,
            'currency_id' => $receipt->currency_id,
            'exchange_rate' => (string) $receipt->exchange_rate,
            'payment_method_id' => $receipt->payment_method_id,
            'payment_date' => $receipt->payment_date?->toDateString(),
            'amount' => (string) $receipt->amount,
            'reference' => $receipt->reference,
            'notes' => $receipt->notes,
            'status' => $receipt->status instanceof CustomerReceiptStatus
                ? $receipt->status->value
                : (string) $receipt->status,
            'customer' => self::customerBrief($receipt->customer),
            'currency' => self::currencyBrief($receipt->currency),
            'payment_method' => self::paymentMethodBrief($receipt->paymentMethod),
            'created_by' => self::userBrief($receipt->createdByUser),
            'posted_by' => self::userBrief($receipt->postedByUser),
            'posted_at' => $receipt->posted_at?->toIso8601String(),
            'allocations_count' => $receipt->allocations_count ?? null,
            'created_at' => (string) $receipt->created_at,
            'updated_at' => (string) $receipt->updated_at,
        ];

        if ($includeAllocations) {
            $receipt->loadMissing([
                'allocations.salesInvoice:id,invoice_number,invoice_date,status,grand_total,paid_total,net_to_pay,currency_id,customer_id',
                'allocations.salesInvoice.currency:id,code,name,symbol',
            ]);
            $payload['allocations'] = self::allocationsToArray($receipt->allocations);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, CustomerReceiptAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    public static function allocationsToArray(Collection $allocations): array
    {
        return $allocations
            ->map(function (CustomerReceiptAllocation $allocation): array {
                $invoice = $allocation->salesInvoice;

                return [
                    'id' => $allocation->id,
                    'sales_invoice_id' => $allocation->sales_invoice_id,
                    'amount' => (string) $allocation->amount,
                    'applied_amount' => (string) $allocation->applied_amount,
                    'applied_exchange_rate' => (string) $allocation->applied_exchange_rate,
                    'currency_id' => $invoice?->currency_id,
                    'currency' => self::currencyBrief($invoice?->currency),
                    'invoice_number' => $invoice?->invoice_number,
                    'invoice_date' => $invoice?->invoice_date?->toDateString(),
                    'invoice_status' => $invoice?->status instanceof SalesInvoiceStatus
                        ? $invoice->status->value
                        : ($invoice?->status !== null ? (string) $invoice->status : null),
                    'grand_total' => $invoice ? (string) $invoice->grand_total : null,
                    'paid_total' => $invoice ? (string) $invoice->paid_total : null,
                    'net_to_pay' => $invoice ? (string) $invoice->net_to_pay : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function openInvoice(SalesInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'grand_total' => (string) $invoice->grand_total,
            'paid_total' => (string) $invoice->paid_total,
            'net_to_pay' => (string) $invoice->net_to_pay,
            'currency_id' => $invoice->currency_id,
            'currency' => self::currencyBrief($invoice->currency),
            'customer_id' => $invoice->customer_id,
        ];
    }

    /**
     * @return array{id:string,name:?string,customer_code:?string}|null
     */
    private static function customerBrief(?Customer $customer): ?array
    {
        if (! $customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'customer_code' => $customer->customer_code,
        ];
    }

    /**
     * @return array{id:int,code:?string,name:?string,symbol:?string}|null
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
     * @return array{id:int,name:?string,code:?string,type:?string,requires_reference:bool}|null
     */
    private static function paymentMethodBrief(?PaymentMethod $method): ?array
    {
        if (! $method) {
            return null;
        }

        $type = $method->type;

        return [
            'id' => $method->id,
            'name' => $method->name,
            'code' => $method->code,
            'type' => $type instanceof \BackedEnum ? $type->value : ($type !== null ? (string) $type : null),
            'requires_reference' => (bool) $method->requires_reference,
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
