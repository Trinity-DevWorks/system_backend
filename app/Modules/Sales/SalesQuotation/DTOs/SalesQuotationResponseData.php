<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\DTOs;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Sales\SalesQuotation\Enums\SalesQuotationStatus;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;
use App\Modules\Salesman\Models\Salesman;

readonly class SalesQuotationResponseData
{
    public static function fromModel(SalesQuotation $quotation, bool $includeLines = true): array
    {
        $quotation->loadMissing([
            'customer:id,customer_code,name,phone,status,is_system,salesman_id,payment_terms_id',
            'currency:id,code,name,symbol',
            'salesman:id,full_name,salesman_code',
            'paymentTerm:id,name,code,due_days',
            'salesOrder:id,order_number,status',
            'createdByUser:id,name,email',
            'sentByUser:id,name,email',
            'acceptedByUser:id,name,email',
            'rejectedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'customer_id' => $quotation->customer_id,
            'currency_id' => $quotation->currency_id,
            'salesman_id' => $quotation->salesman_id,
            'payment_terms_id' => $quotation->payment_terms_id,
            'sales_order_id' => $quotation->sales_order_id,
            'status' => $quotation->status instanceof SalesQuotationStatus
                ? $quotation->status->value
                : (string) $quotation->status,
            'quotation_date' => $quotation->quotation_date?->toDateString(),
            'valid_until' => $quotation->valid_until?->toDateString(),
            'exchange_rate' => (string) $quotation->exchange_rate,
            'subtotal' => (string) $quotation->subtotal,
            'discount_total' => (string) $quotation->discount_total,
            'tax_total' => (string) $quotation->tax_total,
            'grand_total' => (string) $quotation->grand_total,
            'notes' => $quotation->notes,
            'customer' => self::customerBrief($quotation->customer),
            'currency' => self::currencyBrief($quotation->currency),
            'salesman' => self::salesmanBrief($quotation->salesman),
            'payment_term' => self::paymentTermBrief($quotation->paymentTerm),
            'sales_order' => self::orderBrief($quotation->salesOrder),
            'created_by' => self::userBrief($quotation->createdByUser),
            'sent_by' => self::userBrief($quotation->sentByUser),
            'sent_at' => $quotation->sent_at?->toIso8601String(),
            'accepted_by' => self::userBrief($quotation->acceptedByUser),
            'accepted_at' => $quotation->accepted_at?->toIso8601String(),
            'rejected_by' => self::userBrief($quotation->rejectedByUser),
            'rejected_at' => $quotation->rejected_at?->toIso8601String(),
            'lines_count' => $quotation->lines_count ?? null,
            'created_at' => (string) $quotation->created_at,
            'updated_at' => (string) $quotation->updated_at,
        ];

        if ($includeLines) {
            $quotation->loadMissing(['lines.item.itemType', 'lines.item.vatGroup', 'lines.itemUom.uom']);
            $payload['lines'] = SalesQuotationLineResponseData::collectionToArray($quotation->lines);
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
     * @return array{id:string,order_number:?string,status:string}|null
     */
    private static function orderBrief(?SalesOrder $order): ?array
    {
        if (! $order) {
            return null;
        }

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status,
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
