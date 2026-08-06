<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What: Generates short, zero-padded document codes (e.g. CUST-000001) without using UUIDs.
 * Where: CustomerService / SupplierService create flows (and any similar one-shot code needs).
 * Why: Avoid create-then-update (double audit rows) and keep UI-friendly codes under multi-tenant Postgres.
 *      Uses a transaction-scoped advisory lock so concurrent creates cannot collide.
 */
final class SequentialCodeGenerator
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function next(string $modelClass, string $column, string $prefix, int $pad = 6): string
    {
        if ($pad < 1 || $pad > 18) {
            throw new InvalidArgumentException('Pad width must be between 1 and 18.');
        }

        if ($prefix === '') {
            throw new InvalidArgumentException('Prefix must not be empty.');
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException('modelClass must be an Eloquent model.');
        }

        // Serialize next-code allocation for this tenant connection/transaction.
        $lockKey = self::advisoryLockKey($modelClass, $column, $prefix);
        DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

        $pattern = '^'.preg_quote($prefix, '/').'[0-9]+$';

        $latest = $modelClass::query()
            ->whereNotNull($column)
            ->whereRaw("{$column} ~ ?", [$pattern])
            ->orderByRaw("CAST(substring({$column} from '([0-9]+)$') AS BIGINT) DESC")
            ->value($column);

        $next = 1;
        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $matches) === 1) {
            $next = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private static function advisoryLockKey(string $modelClass, string $column, string $prefix): int
    {
        // Postgres advisory locks use a signed 64-bit key; crc32 fits and is stable per code series.
        return crc32($modelClass.'|'.$column.'|'.$prefix);
    }
}
