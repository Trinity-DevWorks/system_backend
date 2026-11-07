<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\DTOs;

use App\Models\User;
use App\Modules\Branch\Support\DocumentBranchPayload;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class PurchaseOrderResponseData
{
    public static function fromModel(PurchaseOrder $order, bool $includeLines = true): array
    {
        $order->loadMissing([
            'supplier:id,supplier_code,name,is_active',
            'warehouse:id,name,shortcut_name,is_active',
            'currency:id,code,name,symbol',
            'createdByUser:id,name,email',
            'confirmedByUser:id,name,email',
            'sentByUser:id,name,email',
        ]);

        $payload = [
            'id' => $order->id,
            ...DocumentBranchPayload::of($order),
            'po_number' => $order->po_number,
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $order->warehouse_id,
            'currency_id' => $order->currency_id,
            'currency' => $order->currency ? [
                'id' => $order->currency->id,
                'code' => $order->currency->code,
                'name' => $order->currency->name,
                'symbol' => $order->currency->symbol,
            ] : null,
            'currency_code' => $order->currency?->code,
            'exchange_rate' => (string) $order->exchange_rate,
            'subtotal' => (string) $order->subtotal,
            'discount_total' => (string) $order->discount_total,
            'tax_total' => (string) $order->tax_total,
            'grand_total' => (string) $order->grand_total,
            'status' => $order->status->value,
            'order_date' => $order->order_date?->toDateString(),
            'expected_date' => $order->expected_date?->toDateString(),
            'notes' => $order->notes,
            'supplier' => self::supplierBrief($order->supplier),
            'warehouse' => self::warehouseBrief($order->warehouse),
            'created_by' => self::userBrief($order->createdByUser),
            'confirmed_by' => self::userBrief($order->confirmedByUser),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'sent_by' => self::userBrief($order->sentByUser),
            'sent_at' => $order->sent_at?->toIso8601String(),
            'is_sent' => $order->status === PurchaseOrderStatus::Sent,
            'lines_count' => $order->lines_count ?? null,
            'created_at' => (string) $order->created_at,
            'updated_at' => (string) $order->updated_at,
        ];

        if ($includeLines) {
            $order->loadMissing([
                'lines.item',
                'lines.itemUom.uom',
            ]);
            $lines = PurchaseOrderLineResponseData::collectionToArray($order->lines, (string) $order->exchange_rate);
            $payload['lines'] = $lines;
            // Kept for older clients: the stored grand total, never recomputed from lines.
            $payload['total_amount'] = (string) $order->grand_total;
            $payload['can_receive'] = in_array($order->status, [PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::Sent], true)
                && self::hasOpenQuantity($lines);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $orders
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $orders, bool $includeLines = false): array
    {
        return $orders
            ->map(fn (PurchaseOrder $order): array => self::fromModel($order, $includeLines))
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
     * @return array{id:int,name:string,shortcut_name:string,is_active:bool}|null
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

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private static function hasOpenQuantity(array $lines): bool
    {
        foreach ($lines as $line) {
            if (isset($line['open_quantity']) && bccomp((string) $line['open_quantity'], '0', 6) > 0) {
                return true;
            }
        }

        return false;
    }
}
