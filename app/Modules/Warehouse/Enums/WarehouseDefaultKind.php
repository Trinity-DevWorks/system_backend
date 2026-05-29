<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Enums;

use InvalidArgumentException;

enum WarehouseDefaultKind: string
{
    case General = 'general';
    case Sales = 'sales';
    case Production = 'production';
    case Purchase = 'purchase';
    case Storage = 'storage';

    public function column(): string
    {
        return match ($this) {
            self::General => 'is_default',
            self::Sales => 'is_default_sales',
            self::Production => 'is_default_production',
            self::Purchase => 'is_default_purchase',
            self::Storage => 'is_default_storage',
        };
    }

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return array_map(
            static fn (self $kind): string => $kind->column(),
            self::cases()
        );
    }

    public static function parse(string $kind): self
    {
        $normalized = strtolower(trim($kind));

        $resolved = self::tryFrom($normalized);
        if ($resolved !== null) {
            return $resolved;
        }

        throw new InvalidArgumentException(
            sprintf('Unknown warehouse default kind "%s". Expected one of: %s.', $kind, implode(', ', array_column(self::cases(), 'value')))
        );
    }
}
