<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\Support;

/**
 * Default behavioral flags per system item type code (service-layer hints only).
 */
final class ItemTypeDefaults
{
    /**
     * @return array{track_inventory: bool, allow_sale: bool, allow_purchase: bool}
     */
    public static function flagsForCode(string $code): array
    {
        return match (strtoupper(trim($code))) {
            'INVENTORY' => [
                'track_inventory' => true,
                'allow_sale' => true,
                'allow_purchase' => true,
            ],
            'SERVICE' => [
                'track_inventory' => false,
                'allow_sale' => true,
                'allow_purchase' => false,
            ],
            'INGREDIENT' => [
                'track_inventory' => true,
                'allow_sale' => false,
                'allow_purchase' => true,
            ],
            'PRODUCE' => [
                'track_inventory' => true,
                'allow_sale' => true,
                'allow_purchase' => false,
            ],
            'BUNDLE' => [
                'track_inventory' => false,
                'allow_sale' => true,
                'allow_purchase' => false,
            ],
            'NON_INVENTORY' => [
                'track_inventory' => false,
                'allow_sale' => true,
                'allow_purchase' => true,
            ],
            'PLU' => [
                'track_inventory' => false,
                'allow_sale' => true,
                'allow_purchase' => false,
            ],
            default => [
                'track_inventory' => true,
                'allow_sale' => true,
                'allow_purchase' => true,
            ],
        };
    }

    public static function requiresBaseUom(string $code): bool
    {
        return self::flagsForCode($code)['track_inventory'];
    }

    /**
     * @return array{send_to_kitchen: bool, qr_enabled: bool}
     */
    public static function posFlagsForCode(string $code): array
    {
        return match (strtoupper(trim($code))) {
            'SERVICE', 'PRODUCE', 'PLU', 'BUNDLE' => [
                'send_to_kitchen' => true,
                'qr_enabled' => false,
            ],
            default => [
                'send_to_kitchen' => false,
                'qr_enabled' => false,
            ],
        };
    }
}
