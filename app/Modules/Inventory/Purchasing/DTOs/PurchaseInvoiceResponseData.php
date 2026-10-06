<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\DTOs;

use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;

readonly class PurchaseInvoiceResponseData
{
    /**
     * @param  array{kind: string, checked_at: string|null}|null  $chainIssue
     * @param  array{status: string, financed: bool, checked_at: string|null}|null  $chainStatus
     */
    public static function fromModel(
        PurchaseInvoice $invoice,
        bool $includeLines = true,
        ?array $chainIssue = null,
        ?array $chainStatus = null,
    ): array {
        $invoice->loadMissing([
            'supplier:id,supplier_code,name,phone,is_active,payment_method_id,payment_terms_id,wallet_address,wallet_type',
            'goodsReceipt:id,grn_number,supplier_id,warehouse_id,status',
            'purchaseOrder:id,po_number,supplier_id,warehouse_id,status',
            'warehouse:id,name,shortcut_name,is_active',
            'currency:id,code,name,symbol',
            'paymentMethod:id,name,code',
            'paymentTerm:id,name,code,due_days',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
            'replacesInvoice:id,invoice_number,status',
            'replacedByInvoice:id,invoice_number,status,replaces_invoice_id',
        ]);

        $payload = [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'supplier_id' => $invoice->supplier_id,
            'goods_receipt_id' => $invoice->goods_receipt_id,
            'purchase_order_id' => $invoice->purchase_order_id,
            'warehouse_id' => $invoice->warehouse_id,
            'currency_id' => $invoice->currency_id,
            'payment_method_id' => $invoice->payment_method_id,
            'payment_terms_id' => $invoice->payment_terms_id,
            'status' => $invoice->status instanceof PurchaseInvoiceStatus
                ? $invoice->status->value
                : (string) $invoice->status,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'exchange_rate' => (string) $invoice->exchange_rate,
            'reference_2' => $invoice->reference_2,
            'subtotal' => (string) $invoice->subtotal,
            'discount_total' => (string) $invoice->discount_total,
            'tax_total' => (string) $invoice->tax_total,
            'adjustment' => (string) $invoice->adjustment,
            'grand_total' => (string) $invoice->grand_total,
            'paid_total' => (string) $invoice->paid_total,
            'net_to_pay' => (string) $invoice->net_to_pay,
            'notes' => $invoice->notes,
            'posted_at' => $invoice->posted_at?->toIso8601String(),
            'created_at' => $invoice->created_at?->toIso8601String(),
            'supplier' => $invoice->supplier ? [
                'id' => $invoice->supplier->id,
                'supplier_code' => $invoice->supplier->supplier_code,
                'name' => $invoice->supplier->name,
                'phone' => $invoice->supplier->phone,
                'wallet_address' => $invoice->supplier->wallet_address,
                'wallet_type' => $invoice->supplier->wallet_type instanceof \BackedEnum
                    ? $invoice->supplier->wallet_type->value
                    : ($invoice->supplier->wallet_type !== null ? (string) $invoice->supplier->wallet_type : null),
            ] : null,
            'goods_receipt' => $invoice->goodsReceipt ? [
                'id' => $invoice->goodsReceipt->id,
                'grn_number' => $invoice->goodsReceipt->grn_number,
            ] : null,
            'purchase_order' => $invoice->purchaseOrder ? [
                'id' => $invoice->purchaseOrder->id,
                'po_number' => $invoice->purchaseOrder->po_number,
            ] : null,
            'warehouse' => $invoice->warehouse ? [
                'id' => $invoice->warehouse->id,
                'name' => $invoice->warehouse->name,
                'shortcut_name' => $invoice->warehouse->shortcut_name,
            ] : null,
            'currency' => $invoice->currency ? [
                'id' => $invoice->currency->id,
                'code' => $invoice->currency->code,
                'name' => $invoice->currency->name,
            ] : null,
            'payment_method' => $invoice->paymentMethod ? [
                'id' => $invoice->paymentMethod->id,
                'name' => $invoice->paymentMethod->name,
                'code' => $invoice->paymentMethod->code,
            ] : null,
            'payment_term' => $invoice->paymentTerm ? [
                'id' => $invoice->paymentTerm->id,
                'name' => $invoice->paymentTerm->name,
                'code' => $invoice->paymentTerm->code,
                'due_days' => $invoice->paymentTerm->due_days,
            ] : null,
            'posted_by' => $invoice->postedByUser ? [
                'id' => $invoice->postedByUser->id,
                'name' => $invoice->postedByUser->name,
            ] : null,
            'replaces_invoice_id' => $invoice->replaces_invoice_id,
            'linked_proof_id' => $invoice->linked_proof_id,
            'linked_dispute_reason' => $invoice->linked_dispute_reason,
            'replaces_invoice' => self::invoiceLinkBrief($invoice->replacesInvoice),
            'replaced_by_invoice' => self::invoiceLinkBrief($invoice->replacedByInvoice),
            'can_reverse' => self::canReverse($invoice),
            'can_reissue' => self::canReissue($invoice),
            'chain_issue' => $chainIssue,
            'chain_status' => $chainStatus,
        ];

        if ($includeLines) {
            $invoice->loadMissing('lines');
            $payload['lines'] = PurchaseInvoiceLineResponseData::collectionToArray($invoice->lines);
        }

        return $payload;
    }

    /**
     * @return array{id: string, invoice_number: ?string, status: ?string}|null
     */
    private static function invoiceLinkBrief(mixed $invoice): ?array
    {
        if (! $invoice instanceof PurchaseInvoice) {
            return null;
        }

        $status = $invoice->status instanceof PurchaseInvoiceStatus
            ? $invoice->status->value
            : (is_string($invoice->status) ? $invoice->status : null);

        return [
            'id' => (string) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $status,
        ];
    }

    private static function canReverse(PurchaseInvoice $invoice): bool
    {
        return $invoice->status === PurchaseInvoiceStatus::Posted
            && bccomp((string) $invoice->paid_total, '0', 4) <= 0;
    }

    private static function canReissue(PurchaseInvoice $invoice): bool
    {
        if (! in_array($invoice->status, [PurchaseInvoiceStatus::Posted, PurchaseInvoiceStatus::Reversed], true)) {
            return false;
        }
        if (bccomp((string) $invoice->paid_total, '0', 4) > 0) {
            return false;
        }
        if ($invoice->goods_receipt_id !== null) {
            return false;
        }
        if ($invoice->linked_proof_id !== null) {
            return false;
        }

        return $invoice->replacedByInvoice === null;
    }
}
