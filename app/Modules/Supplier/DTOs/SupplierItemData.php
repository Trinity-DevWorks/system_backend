<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\Currency\Models\Currency;
use App\Modules\Supplier\Http\Requests\StoreSupplierItemRequest;
use App\Modules\Supplier\Http\Requests\UpdateSupplierItemRequest;
use App\Modules\Supplier\Models\SupplierItem;

readonly class SupplierItemData
{
    public function __construct(
        public int $itemId,
        public ?string $supplierSku,
        public ?string $lastPurchasePrice,
        public int $currencyId,
        public int $leadTimeDays,
        public bool $isPreferred,
    ) {}

    public static function fromStoreRequest(StoreSupplierItemRequest $request): self
    {
        $data = $request->validated();

        return new self(
            itemId: (int) $data['item_id'],
            supplierSku: self::normalizeSku($data['supplier_sku'] ?? null),
            lastPurchasePrice: self::normalizePrice($data['last_purchase_price'] ?? null),
            currencyId: self::resolveCurrencyId($data['currency_id'] ?? null),
            leadTimeDays: max(0, (int) ($data['lead_time_days'] ?? 0)),
            isPreferred: (bool) ($data['is_preferred'] ?? false),
        );
    }

    public static function fromUpdateRequest(UpdateSupplierItemRequest $request, SupplierItem $row): self
    {
        $data = $request->validated();

        return new self(
            itemId: (int) $row->item_id,
            supplierSku: array_key_exists('supplier_sku', $data)
                ? self::normalizeSku($data['supplier_sku'])
                : $row->supplier_sku,
            lastPurchasePrice: array_key_exists('last_purchase_price', $data)
                ? self::normalizePrice($data['last_purchase_price'])
                : ($row->last_purchase_price !== null ? (string) $row->last_purchase_price : null),
            currencyId: array_key_exists('currency_id', $data)
                ? (int) $data['currency_id']
                : (int) $row->currency_id,
            leadTimeDays: array_key_exists('lead_time_days', $data)
                ? max(0, (int) $data['lead_time_days'])
                : (int) $row->lead_time_days,
            isPreferred: array_key_exists('is_preferred', $data)
                ? (bool) $data['is_preferred']
                : (bool) $row->is_preferred,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'supplier_sku' => $this->supplierSku,
            'last_purchase_price' => $this->lastPurchasePrice,
            'currency_id' => $this->currencyId,
            'lead_time_days' => $this->leadTimeDays,
            'is_preferred' => $this->isPreferred,
        ];
    }

    private static function normalizeSku(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizePrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    private static function resolveCurrencyId(mixed $currencyId): int
    {
        if ($currencyId !== null && $currencyId !== '') {
            return (int) $currencyId;
        }

        $primary = Currency::getPrimary();
        if (! $primary) {
            abort(422, 'Set a primary currency before linking supplier items.', ['X-Error-Code' => 'PRIMARY_CURRENCY_REQUIRED']);
        }

        return (int) $primary->id;
    }
}
