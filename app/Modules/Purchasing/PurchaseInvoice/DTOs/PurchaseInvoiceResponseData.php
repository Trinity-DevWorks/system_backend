<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\DTOs;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Purchasing\PurchaseInvoice\Enums\PurchaseInvoiceStatus;
use App\Modules\Purchasing\PurchaseInvoice\Models\PurchaseInvoice;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Collection;

readonly class PurchaseInvoiceResponseData
{
    public static function fromModel(PurchaseInvoice $invoice, bool $includeLines = true): array
    {
        $invoice->loadMissing([
            'supplier:id,supplier_code,name,is_active',
            'currency:id,code,name,symbol,iso_code,is_active',
            'paymentTerm:id,code,name,due_days',
            'paymentMethod:id,code,name',
            'purchaseOrder:id,po_number,status',
            'goodsReceipt:id,grn_number,status',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'supplier_id' => $invoice->supplier_id,
            'currency_id' => $invoice->currency_id,
            'payment_terms_id' => $invoice->payment_terms_id,
            'payment_method_id' => $invoice->payment_method_id,
            'purchase_order_id' => $invoice->purchase_order_id,
            'goods_receipt_id' => $invoice->goods_receipt_id,
            'status' => $invoice->status->value,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'supplier_reference' => $invoice->supplier_reference,
            'notes' => $invoice->notes,
            'subtotal' => (string) $invoice->subtotal,
            'tax_total' => (string) $invoice->tax_total,
            'grand_total' => (string) $invoice->grand_total,
            'supplier' => self::supplierBrief($invoice->supplier),
            'currency' => self::currencyBrief($invoice->currency),
            'payment_term' => $invoice->paymentTerm ? [
                'id' => $invoice->paymentTerm->id,
                'code' => $invoice->paymentTerm->code,
                'name' => $invoice->paymentTerm->name,
                'due_days' => (int) $invoice->paymentTerm->due_days,
            ] : null,
            'payment_method' => $invoice->paymentMethod ? [
                'id' => $invoice->paymentMethod->id,
                'code' => $invoice->paymentMethod->code,
                'name' => $invoice->paymentMethod->name,
            ] : null,
            'purchase_order' => $invoice->purchaseOrder ? [
                'id' => $invoice->purchaseOrder->id,
                'po_number' => $invoice->purchaseOrder->po_number,
                'status' => $invoice->purchaseOrder->status instanceof \BackedEnum
                    ? $invoice->purchaseOrder->status->value
                    : (string) $invoice->purchaseOrder->status,
            ] : null,
            'goods_receipt' => $invoice->goodsReceipt ? [
                'id' => $invoice->goodsReceipt->id,
                'grn_number' => $invoice->goodsReceipt->grn_number,
                'status' => $invoice->goodsReceipt->status instanceof \BackedEnum
                    ? $invoice->goodsReceipt->status->value
                    : (string) $invoice->goodsReceipt->status,
            ] : null,
            'created_by' => self::userBrief($invoice->createdByUser),
            'posted_by' => self::userBrief($invoice->postedByUser),
            'posted_at' => $invoice->posted_at?->toIso8601String(),
            'is_posted' => $invoice->status === PurchaseInvoiceStatus::Posted,
            'lines_count' => $invoice->lines_count ?? null,
            'created_at' => (string) $invoice->created_at,
            'updated_at' => (string) $invoice->updated_at,
        ];

        if ($includeLines) {
            $invoice->loadMissing([
                'lines.item',
                'lines.itemUom.uom',
                'lines.vatGroup',
            ]);
            $payload['lines'] = PurchaseInvoiceLineResponseData::collectionToArray($invoice->lines);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, PurchaseInvoice>  $invoices
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $invoices, bool $includeLines = false): array
    {
        return $invoices
            ->map(fn (PurchaseInvoice $invoice): array => self::fromModel($invoice, $includeLines))
            ->values()
            ->all();
    }

    /**
     * @return array{id:string,supplier_code:?string,name:string,is_active:bool}|null
     */
    private static function supplierBrief(?Supplier $supplier): ?array
    {
        if (! $supplier) {
            return null;
        }

        return [
            'id' => $supplier->id,
            'supplier_code' => $supplier->supplier_code,
            'name' => $supplier->name,
            'is_active' => (bool) $supplier->is_active,
        ];
    }

    /**
     * @return array{id:int,code:string,name:string,symbol:?string,iso_code:?string,is_active:bool}|null
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
            'iso_code' => $currency->iso_code,
            'is_active' => (bool) $currency->is_active,
        ];
    }

    /**
     * @return array{id:string,name:string,email:string|null}|null
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
