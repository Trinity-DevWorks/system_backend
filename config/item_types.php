<?php

declare(strict_types=1);

/**
 * System item type catalog. Synced on tenant creation by BootstrapTenantItemTypes.
 * Not user-editable via API.
 *
 * code: machine key (uppercase), name: stable slug (lowercase).
 */
return [
    'types' => [
        ['code' => 'INVENTORY', 'name' => 'inventory'],
        ['code' => 'SERVICE', 'name' => 'service'],
        ['code' => 'INGREDIENT', 'name' => 'ingredient'],
        ['code' => 'PRODUCE', 'name' => 'produce'],
        ['code' => 'BUNDLE', 'name' => 'bundle'],
        ['code' => 'NON_INVENTORY', 'name' => 'non_inventory'],
        ['code' => 'PLU', 'name' => 'plu'],
    ],
];
