<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\Support;

use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;

final class BundleItemRules
{
    public const BUNDLE_TYPE_CODE = 'BUNDLE';

    public static function assertIsBundleItem(Item $item): void
    {
        $item->loadMissing('itemType:id,code');

        if (! $item->itemType || strtoupper($item->itemType->code) !== self::BUNDLE_TYPE_CODE) {
            abort(422, 'Item must be of type bundle.', ['X-Error-Code' => 'ITEM_NOT_BUNDLE_TYPE']);
        }

        if (! $item->is_active) {
            abort(422, 'Bundle item must be active.', ['X-Error-Code' => 'BUNDLE_ITEM_INACTIVE']);
        }
    }

    public static function assertValidChild(Item $bundle, Item $child): void
    {
        if ((int) $bundle->id === (int) $child->id) {
            abort(422, 'A bundle cannot contain itself.', ['X-Error-Code' => 'BUNDLE_SELF_REFERENCE']);
        }

        if (! $child->is_active) {
            abort(422, 'Bundle component must be an active item.', ['X-Error-Code' => 'BUNDLE_CHILD_INACTIVE']);
        }

        $child->loadMissing('itemType:id,code');
        if ($child->itemType && strtoupper($child->itemType->code) === self::BUNDLE_TYPE_CODE) {
            abort(422, 'Nested bundles are not supported.', ['X-Error-Code' => 'BUNDLE_NESTED_NOT_ALLOWED']);
        }

        if (self::bundleContainsItem($child->id, $bundle->id)) {
            abort(422, 'This would create a circular bundle reference.', ['X-Error-Code' => 'BUNDLE_CIRCULAR_REFERENCE']);
        }
    }

    /**
     * True if $rootItemId is a bundle that already includes $targetItemId as a component (any depth).
     */
    private static function bundleContainsItem(int $rootItemId, int $targetItemId): bool
    {
        $visited = [];
        $stack = [$rootItemId];

        while ($stack !== []) {
            $currentId = array_pop($stack);
            if (isset($visited[$currentId])) {
                continue;
            }
            $visited[$currentId] = true;

            $componentIds = BundleItem::query()
                ->where('bundle_item_id', $currentId)
                ->pluck('child_item_id')
                ->all();

            foreach ($componentIds as $componentId) {
                $componentId = (int) $componentId;
                if ($componentId === $targetItemId) {
                    return true;
                }
                $stack[] = $componentId;
            }
        }

        return false;
    }
}
