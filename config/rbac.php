<?php

declare(strict_types=1);

/**
 * Permission catalog: resource_key => display label.
 * Synced into tenant `permissions` table by BootstrapTenantRbac.
 */
return [
    'resources' => [
        'users' => 'User Management',
        'roles' => 'Role Management',
        'permissions' => 'Permission Management',

        'brands' => 'Brand Management',
        'categories' => 'Category Management',
        'vat_groups' => 'VAT Group Management',
        'currencies' => 'Currency Management',
        'payment_methods' => 'Payment Method Management',
        'payment_terms' => 'Payment Term Management',
        'warehouses' => 'Warehouse Management',
        'stock' => 'Stock Management',
        'salesmen' => 'Salesman Management',

        'unit_groups' => 'Unit Group Management',
        'unit_of_measurements' => 'Unit Of Measurement Management',
        'items' => 'Item Management',

        'customer_groups' => 'Customer Group Management',
        'customers' => 'Customer Management',

        'supplier_groups' => 'Supplier Group Management',
        'suppliers' => 'Supplier Management',
    ],
];
