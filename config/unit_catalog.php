<?php

declare(strict_types=1);

/**
 * Default unit groups and units of measurement. Synced on tenant creation by BootstrapTenantUnitCatalog.
 * Users can edit these via the unit groups / UOM API after seeding.
 *
 * Groups define convertible families (dimension_type). Item base UOM and alternate item UOMs must share a group.
 */
return [
    'groups' => [
        [
            'code' => 'COUNT',
            'name' => 'Count / Piece',
            'dimension_type' => 'count',
            'units' => [
                ['code' => 'EA', 'name' => 'Each', 'symbol' => 'ea', 'decimal_places' => 0],
                ['code' => 'DZ', 'name' => 'Dozen', 'symbol' => 'dz', 'decimal_places' => 0],
                ['code' => 'PK', 'name' => 'Pack', 'symbol' => 'pk', 'decimal_places' => 0],
                ['code' => 'BX', 'name' => 'Box', 'symbol' => 'bx', 'decimal_places' => 0],
                ['code' => 'CS', 'name' => 'Case', 'symbol' => 'cs', 'decimal_places' => 0],
            ],
        ],
        [
            'code' => 'WEIGHT',
            'name' => 'Weight',
            'dimension_type' => 'weight',
            'units' => [
                ['code' => 'G', 'name' => 'Gram', 'symbol' => 'g', 'decimal_places' => 2],
                ['code' => 'KG', 'name' => 'Kilogram', 'symbol' => 'kg', 'decimal_places' => 3],
                ['code' => 'LB', 'name' => 'Pound', 'symbol' => 'lb', 'decimal_places' => 3],
                ['code' => 'OZ', 'name' => 'Ounce', 'symbol' => 'oz', 'decimal_places' => 2],
            ],
        ],
        [
            'code' => 'VOLUME',
            'name' => 'Volume',
            'dimension_type' => 'volume',
            'units' => [
                ['code' => 'ML', 'name' => 'Milliliter', 'symbol' => 'ml', 'decimal_places' => 0],
                ['code' => 'CL', 'name' => 'Centiliter', 'symbol' => 'cl', 'decimal_places' => 1],
                ['code' => 'L', 'name' => 'Liter', 'symbol' => 'l', 'decimal_places' => 3],
                ['code' => 'GAL', 'name' => 'Gallon', 'symbol' => 'gal', 'decimal_places' => 3],
            ],
        ],
    ],
];
