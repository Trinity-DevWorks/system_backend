<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Purchasing\DTOs\PurchaseOrderResponseData;
use App\Modules\Inventory\Stock\Services\PurchasingAlertService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Facades\DB;

class PurchaseOrderFromAlertsService
{
    public function __construct(
        private readonly PurchasingAlertService $purchasingAlertService,
        private readonly PurchaseOrderService $purchaseOrderService,
    ) {}

    /**
     * @param  list<int>  $replenishmentIds
     * @param  array<int|string, string>  $supplierOverrides
     * @return array{
     *   groups: list<array<string, mixed>>,
     *   skipped: list<array<string, mixed>>,
     *   purchase_orders: list<array<string, mixed>>
     * }
     */
    public function handle(array $replenishmentIds, array $supplierOverrides, bool $preview, ?string $userId): array
    {
        $replenishmentIds = array_values(array_unique(array_map('intval', $replenishmentIds)));
        $supplierOverrides = $this->normalizeSupplierOverrides($supplierOverrides);

        $alertsById = $this->purchasingAlertService->findByReplenishmentIds($replenishmentIds);

        $resolved = [];
        $skipped = [];

        foreach ($replenishmentIds as $replenishmentId) {
            $alert = $alertsById[$replenishmentId] ?? null;

            if ($alert === null) {
                $skipped[] = [
                    'replenishment_id' => $replenishmentId,
                    'reason' => 'not_found',
                ];

                continue;
            }

            $supplierId = $supplierOverrides[$replenishmentId]
                ?? ($alert['preferred_supplier']['id'] ?? null);

            if (! is_string($supplierId) || $supplierId === '') {
                $skipped[] = $this->skippedRow($alert, 'missing_supplier');

                continue;
            }

            $quantity = (float) ($alert['suggested_order_qty'] ?? 0);
            if ($quantity <= 0) {
                $skipped[] = $this->skippedRow($alert, 'zero_quantity');

                continue;
            }

            $resolved[] = [
                'alert' => $alert,
                'supplier_id' => $supplierId,
                'quantity' => $quantity,
            ];
        }

        $groups = $this->groupResolvedRows($resolved);
        $groups = $this->enrichGroupSupplierNames($groups);

        if ($preview || $groups === []) {
            return [
                'groups' => $groups,
                'skipped' => $skipped,
                'purchase_orders' => [],
            ];
        }

        $purchaseOrders = DB::transaction(function () use ($groups, $userId): array {
            $created = [];

            foreach ($groups as $group) {
                $lines = array_map(
                    static fn (array $line): array => [
                        'item_id' => (string) $line['item_id'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                    ],
                    $group['lines'],
                );

                $order = $this->purchaseOrderService->create([
                    'supplier_id' => (string) $group['supplier_id'],
                    'warehouse_id' => (int) $group['warehouse_id'],
                    'lines' => $lines,
                ], $userId);

                $created[] = PurchaseOrderResponseData::fromModel($order);
            }

            return $created;
        });

        return [
            'groups' => $groups,
            'skipped' => $skipped,
            'purchase_orders' => $purchaseOrders,
        ];
    }

    /**
     * @param  array<int|string, string>  $supplierOverrides
     * @return array<int, string>
     */
    private function normalizeSupplierOverrides(array $supplierOverrides): array
    {
        $normalized = [];

        foreach ($supplierOverrides as $replenishmentId => $supplierId) {
            if (! is_numeric($replenishmentId) || ! is_string($supplierId) || $supplierId === '') {
                continue;
            }

            $normalized[(int) $replenishmentId] = $supplierId;
        }

        return $normalized;
    }

    /**
     * @param  list<array{alert: array<string, mixed>, supplier_id: string, quantity: float}>  $resolved
     * @return list<array<string, mixed>>
     */
    private function groupResolvedRows(array $resolved): array
    {
        /** @var array<string, array<string, mixed>> $grouped */
        $grouped = [];

        foreach ($resolved as $row) {
            $alert = $row['alert'];
            $supplierId = $row['supplier_id'];
            $warehouseId = (int) $alert['warehouse_id'];
            $groupKey = $supplierId.'|'.$warehouseId;

            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'supplier_id' => $supplierId,
                    'supplier_name' => $alert['preferred_supplier']['name'] ?? null,
                    'warehouse_id' => $warehouseId,
                    'warehouse_name' => $alert['warehouse']['name'] ?? null,
                    'warehouse_shortcut' => $alert['warehouse']['shortcut_name'] ?? null,
                    'lines' => [],
                ];
            }

            if ($grouped[$groupKey]['supplier_name'] === null && isset($alert['preferred_supplier']['name'])) {
                $grouped[$groupKey]['supplier_name'] = $alert['preferred_supplier']['name'];
            }

            $itemId = (string) $alert['item_id'];
            $lineIndex = null;

            foreach ($grouped[$groupKey]['lines'] as $index => $existingLine) {
                if ($existingLine['item_id'] === $itemId) {
                    $lineIndex = $index;

                    break;
                }
            }

            $unitPrice = null;
            if (($alert['preferred_supplier']['id'] ?? null) === $supplierId) {
                $unitPrice = $alert['preferred_supplier']['last_purchase_price'] ?? null;
            }

            $linePayload = [
                'replenishment_id' => $alert['replenishment_id'],
                'item_id' => $itemId,
                'item_sku' => $alert['item']['sku'] ?? null,
                'item_name' => $alert['item']['name'] ?? null,
                'quantity' => number_format($row['quantity'], 6, '.', ''),
                'unit_price' => $unitPrice,
            ];

            if ($lineIndex !== null) {
                $existingQty = (float) $grouped[$groupKey]['lines'][$lineIndex]['quantity'];
                $grouped[$groupKey]['lines'][$lineIndex]['quantity'] = number_format(
                    $existingQty + $row['quantity'],
                    6,
                    '.',
                    '',
                );
            } else {
                $grouped[$groupKey]['lines'][] = $linePayload;
            }
        }

        return array_values($grouped);
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function enrichGroupSupplierNames(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        $supplierIds = array_values(array_unique(array_map(
            static fn (array $group): string => (string) $group['supplier_id'],
            $groups,
        )));

        $namesById = Supplier::query()
            ->whereIn('id', $supplierIds)
            ->pluck('name', 'id')
            ->all();

        foreach ($groups as $index => $group) {
            $supplierId = (string) $group['supplier_id'];
            if (empty($group['supplier_name']) && isset($namesById[$supplierId])) {
                $groups[$index]['supplier_name'] = $namesById[$supplierId];
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $alert
     * @return array<string, mixed>
     */
    private function skippedRow(array $alert, string $reason): array
    {
        return [
            'replenishment_id' => $alert['replenishment_id'],
            'item_id' => $alert['item_id'],
            'item_sku' => $alert['item']['sku'] ?? null,
            'item_name' => $alert['item']['name'] ?? null,
            'warehouse_id' => $alert['warehouse_id'],
            'reason' => $reason,
        ];
    }
}
