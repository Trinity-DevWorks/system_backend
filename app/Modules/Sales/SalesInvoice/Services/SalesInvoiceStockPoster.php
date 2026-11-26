<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Services;

use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Support\RecipeConsumption;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;

/**
 * Stock: takes the sold goods out of their warehouses (bundles as their components, made-on-sale items as
 * their recipe's ingredients).
 * Part of the sales invoice posting pipeline (see SalesInvoiceService).
 */
class SalesInvoiceStockPoster
{
    public function __construct(
        private readonly StockMovementService $stockMovementService,
    ) {}

    /**
     * Takes every line's goods out of stock. Lines billed from a delivery note are skipped: those goods
     * already left the warehouse with the note.
     */
    public function postInvoice(SalesInvoice $invoice, ?string $userId): void
    {
        $lines = SalesInvoiceLine::query()
            ->where('sales_invoice_id', $invoice->id)
            ->with(['item.itemType', 'lot'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lines as $line) {
            if ($line->delivery_note_line_id !== null) {
                continue;
            }
            $this->postLineStock($invoice, $line, $userId);
        }
    }

    private function postLineStock(SalesInvoice $invoice, SalesInvoiceLine $line, ?string $userId): void
    {
        $item = $line->item ?? Item::query()->findOrFail($line->item_id);

        if (SalesInvoiceRules::isBundleItem($item)) {
            $this->postBundleComponents($invoice, $line, $item, $userId);

            return;
        }
        if (RecipeConsumption::isMadeOnSale($item)) {
            $this->postRecipeIngredients($invoice, $line, $item, $userId);

            return;
        }

        if (! $item->track_inventory) {
            return;
        }

        $warehouseId = (int) ($line->warehouse_id ?? $invoice->warehouse_id);
        $note = trim('SI '.$invoice->invoice_number);
        $outbound = bcmul((string) $line->base_quantity, '-1', 6);

        $this->stockMovementService->post(StockMovementData::forSale(
            itemId: (string) $line->item_id,
            warehouseId: $warehouseId,
            quantityDelta: $outbound,
            salesInvoiceId: (string) $invoice->id,
            itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
            notes: $line->notes ? $note.' — '.$line->notes : $note,
            userId: $userId,
            lotId: $line->lot_id ? (int) $line->lot_id : null,
        )->withSerials($line->serial_numbers, 'customer', (string) $invoice->customer_id));
    }

    /**
     * Made on sale: the recipe's ingredients leave stock (recipe qty × sold qty ÷ yield), not the item itself.
     */
    private function postRecipeIngredients(SalesInvoice $invoice, SalesInvoiceLine $line, Item $item, ?string $userId): void
    {
        $warehouseId = (int) ($line->warehouse_id ?? $invoice->warehouse_id);
        $note = trim('SI '.$invoice->invoice_number).' '.RecipeConsumption::TAG.' '.$item->item_code;
        foreach (RecipeConsumption::ingredients($item, (string) $line->base_quantity, 'SALES_INVOICE') as $ingredient) {
            $this->stockMovementService->post(StockMovementData::forRecipeIngredient(
                itemId: (string) $ingredient['item']->id,
                warehouseId: $warehouseId,
                quantityDelta: bcmul($ingredient['base_quantity'], '-1', 6),
                documentId: (string) $invoice->id,
                notes: $note,
                userId: $userId,
            ));
        }
    }

    private function postBundleComponents(
        SalesInvoice $invoice,
        SalesInvoiceLine $line,
        Item $bundle,
        ?string $userId,
    ): void {
        $components = BundleItem::query()
            ->where('bundle_item_id', $bundle->id)
            ->with('childItem.itemType')
            ->get();

        if ($components->isEmpty()) {
            abort(422, 'Bundle has no components.', ['X-Error-Code' => 'SALES_INVOICE_BUNDLE_EMPTY']);
        }

        $warehouseId = (int) ($line->warehouse_id ?? $invoice->warehouse_id);
        $note = trim('SI '.$invoice->invoice_number).' bundle '.$bundle->item_code;

        foreach ($components as $component) {
            $child = $component->childItem;
            if (! $child instanceof Item || ! $child->track_inventory) {
                continue;
            }
            if ($child->track_lots) {
                abort(422, 'Lot-tracked bundle components cannot be sold on an invoice. Explode the bundle first.', [
                    'X-Error-Code' => 'SALES_INVOICE_BUNDLE_LOT_NOT_SUPPORTED',
                ]);
            }
            if ($child->track_serials) {
                abort(422, 'Bundles with serial-numbered components cannot be sold on an invoice. Explode the bundle first.', [
                    'X-Error-Code' => 'SALES_INVOICE_BUNDLE_SERIAL_NOT_SUPPORTED',
                ]);
            }

            $baseQty = bcmul((string) $line->base_quantity, (string) $component->quantity, 6);
            $outbound = bcmul($baseQty, '-1', 6);

            $this->stockMovementService->post(StockMovementData::forSaleBundleComponent(
                itemId: (string) $child->id,
                warehouseId: $warehouseId,
                quantityDelta: $outbound,
                salesInvoiceId: (string) $invoice->id,
                notes: $note,
                userId: $userId,
            ));
        }
    }
}
