<?php

declare(strict_types=1);

namespace App\Modules\Currency\Services;

use App\Modules\Currency\Models\Currency;

class CurrencyResolverService
{
    public function resolveId(int|string $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $id = (int) $value;

            return Currency::query()->whereKey($id)->value('id');
        }

        return Currency::query()->where('code', (string) $value)->value('id');
    }
}
