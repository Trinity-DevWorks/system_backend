<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierPayment\DTOs;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\SupplierPayment\Enums\SupplierPaymentStatus;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPayment;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPaymentAllocation;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Collection;

readonly class SupplierPaymentResponseData
{
    public static function fromModel(SupplierPayment $payment, bool $includeAllocations = true): array
    {
        $payment->loadMissing([
            'supplier:id,supplier_code,name,phone,is_active',
            'currency:id,code,name,symbol',
            'paymentMethod:id,name,code,type,requires_reference,is_active',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'supplier_id' => $payment->supplier_id,
            'currency_id' => $payment->currency_id,
            'exchange_rate' => (string) $payment->exchange_rate,
            'payment_method_id' => $payment->payment_method_id,
            'payment_date' => $payment->payment_date?->toDateString(),
            'amount' => (string) $payment->amount,
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'status' => $payment->status instanceof SupplierPaymentStatus
                ? $payment->status->value
                : (string) $payment->status,
            'supplier' => self::supplierBrief($payment->supplier),
            'currency' => self::currencyBrief($payment->currency),
            'payment_method' => self::paymentMethodBrief($payment->paymentMethod),
            'created_by' => self::userBrief($payment->createdByUser),
            'posted_by' => self::userBrief($payment->postedByUser),
            'posted_at' => $payment->posted_at?->toIso8601String(),
            'allocations_count' => $payment->allocations_count ?? null,
            'created_at' => (string) $payment->created_at,
            'updated_at' => (string) $payment->updated_at,
        ];

        if ($includeAllocations) {
            $payment->loadMissing([
                'allocations.purchaseInvoice:id,invoice_number,invoice_date,status,grand_total,paid_total,net_to_pay,currency_id,supplier_id',
                'allocations.purchaseInvoice.currency:id,code,name,symbol',
            ]);
            $payload['allocations'] = self::allocationsToArray($payment->allocations);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, SupplierPaymentAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    public static function allocationsToArray(Collection $allocations): array
    {
        return $allocations
            ->map(function (SupplierPaymentAllocation $allocation): array {
                $invoice = $allocation->purchaseInvoice;

                return [
                    'id' => $allocation->id,
                    'purchase_invoice_id' => $allocation->purchase_invoice_id,
                    'amount' => (string) $allocation->amount,
                    'applied_amount' => (string) $allocation->applied_amount,
                    'applied_exchange_rate' => (string) $allocation->applied_exchange_rate,
                    'currency_id' => $invoice?->currency_id,
                    'currency' => self::currencyBrief($invoice?->currency),
                    'invoice_number' => $invoice?->invoice_number,
                    'invoice_date' => $invoice?->invoice_date?->toDateString(),
                    'invoice_status' => $invoice?->status instanceof PurchaseInvoiceStatus
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
    public static function openInvoice(PurchaseInvoice $invoice): array
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
            'supplier_id' => $invoice->supplier_id,
        ];
    }

    /**
     * @return array{id:string,name:?string,supplier_code:?string}|null
     */
    private static function supplierBrief(?Supplier $supplier): ?array
    {
        if (! $supplier) {
            return null;
        }

        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'supplier_code' => $supplier->supplier_code,
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
