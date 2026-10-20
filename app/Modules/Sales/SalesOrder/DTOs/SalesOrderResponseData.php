<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\DTOs;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\SalesOrder\Enums\SalesOrderStatus;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Warehouse\Models\Warehouse;

readonly class SalesOrderResponseData
{
    public static function fromModel(SalesOrder $order, bool $includeLines = true): array
    {
        $order->loadMissing([
            'customer:id,customer_code,name,phone,status,is_system,salesman_id,payment_terms_id',
            'warehouse:id,name,shortcut_name,is_active',
            'currency:id,code,name,symbol',
            'salesman:id,full_name,salesman_code',
            'paymentTerm:id,name,code,due_days',
            'salesQuotation:id,quotation_number,status',
            'createdByUser:id,name,email',
            'confirmedByUser:id,name,email',
            'cancelledByUser:id,name,email',
        ]);

        $payload = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_id' => $order->customer_id,
            'warehouse_id' => $order->warehouse_id,
            'currency_id' => $order->currency_id,
            'salesman_id' => $order->salesman_id,
            'payment_terms_id' => $order->payment_terms_id,
            'sales_quotation_id' => $order->sales_quotation_id,
            'status' => $order->status instanceof SalesOrderStatus ? $order->status->value : (string) $order->status,
            'order_date' => $order->order_date?->toDateString(),
            'requested_date' => $order->requested_date?->toDateString(),
            'exchange_rate' => (string) $order->exchange_rate,
            'subtotal' => (string) $order->subtotal,
            'discount_total' => (string) $order->discount_total,
            'tax_total' => (string) $order->tax_total,
            'grand_total' => (string) $order->grand_total,
            'notes' => $order->notes,
            'customer' => self::customerBrief($order->customer),
            'warehouse' => self::warehouseBrief($order->warehouse),
            'currency' => self::currencyBrief($order->currency),
            'salesman' => self::salesmanBrief($order->salesman),
            'payment_term' => self::paymentTermBrief($order->paymentTerm),
            'sales_quotation' => self::quotationBrief($order->salesQuotation),
            'created_by' => self::userBrief($order->createdByUser),
            'confirmed_by' => self::userBrief($order->confirmedByUser),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'cancelled_by' => self::userBrief($order->cancelledByUser),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'lines_count' => $order->lines_count ?? null,
            'created_at' => (string) $order->created_at,
            'updated_at' => (string) $order->updated_at,
        ];

        if ($includeLines) {
            $order->loadMissing(['lines.item.itemType', 'lines.item.vatGroup', 'lines.itemUom.uom']);
            $payload['lines'] = SalesOrderLineResponseData::collectionToArray($order->lines);
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
     * @return array{id:string,quotation_number:?string}|null
     */
    private static function quotationBrief(?SalesQuotation $quotation): ?array
    {
        if (! $quotation) {
            return null;
        }

        return [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
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
