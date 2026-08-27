<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

final class BundleExplosionScale
{
    public static function factor(string $kitQty): string
    {
        $qty = number_format((float) $kitQty, 6, '.', '');

        if (bccomp($qty, '0', 6) <= 0) {
            abort(422, 'Bundle quantity must be greater than zero.', [
                'X-Error-Code' => 'BUNDLE_EXPLOSION_QTY_INVALID',
            ]);
        }

        return $qty;
    }

    public static function apply(string $componentQty, string $factor): string
    {
        return number_format((float) bcmul($componentQty, $factor, 12), 6, '.', '');
    }
}
