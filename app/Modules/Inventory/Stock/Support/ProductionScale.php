<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

final class ProductionScale
{
    public static function factor(string $produceQty, string $yieldQty): string
    {
        $produce = number_format((float) $produceQty, 6, '.', '');
        $yield = number_format((float) $yieldQty, 6, '.', '');

        if (bccomp($produce, '0', 6) <= 0) {
            abort(422, 'Production quantity must be greater than zero.', [
                'X-Error-Code' => 'PRODUCTION_QTY_INVALID',
            ]);
        }

        if (bccomp($yield, '0', 6) <= 0) {
            abort(422, 'Recipe yield must be greater than zero.', [
                'X-Error-Code' => 'PRODUCTION_QTY_INVALID',
            ]);
        }

        return bcdiv($produce, $yield, 12);
    }

    public static function apply(string $recipeQty, string $factor): string
    {
        return number_format((float) bcmul($recipeQty, $factor, 12), 6, '.', '');
    }
}
