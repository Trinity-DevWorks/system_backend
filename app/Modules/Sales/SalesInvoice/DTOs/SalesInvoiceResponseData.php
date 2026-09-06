<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\DTOs;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Warehouse\Models\Warehouse;

readonly class SalesInvoiceResponseData
{
    public static function fromModel(SalesInvoice $invoice, bool $includeLines = true): array
    {
        $invoice->loadMissing([
            'customer:id,customer_code,name,status,is_system,salesman_id,payment_method_id,payment_terms_id',
            'warehouse:id,name,shortcut_name,is_active',
            'currency:id,code,name,symbol',
            'salesman:id,full_name,salesman_code',
            'paymentMethod:id,name,code',
            'paymentTerm:id,name,code,due_days',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'customer_id' => $invoice->customer_id,
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
            'tax_total' => (string) $invoice->tax_total,
            'adjustment' => (string) $invoice->adjustment,
            'grand_total' => (string) $invoice->grand_total,
            'paid_total' => (string) $invoice->paid_total,
            'net_to_pay' => (string) $invoice->net_to_pay,
            'notes' => $invoice->notes,
            'customer' => self::customerBrief($invoice->customer),
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
            ]);
            $payload['lines'] = SalesInvoiceLineResponseData::collectionToArray($invoice->lines);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function customerBrief(?Customer $customer): ?array
    {
        if (! $customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'name' => $customer->name,
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
