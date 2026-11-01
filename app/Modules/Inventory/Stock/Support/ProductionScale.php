<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Support\Decimal;

final class ProductionScale
{
    public static function factor(string $produceQty, string $yieldQty): string
    {
        $produce = Decimal::round($produceQty, 6);
        $yield = Decimal::round($yieldQty, 6);

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
        return Decimal::round(Decimal::mul($recipeQty, $factor, 12), 6);
    }
}
