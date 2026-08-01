<?php

declare(strict_types=1);

/**
 * Sellable product modules (tenant entitlements).
 * Distinct from RBAC: modules = what the tenant bought; permissions = what a user may do.
 *
 * `resources` maps to keys in config/rbac.php for documentation / future tooling.
 */
return [
    'catalog' => [
        'core' => [
            'name' => 'Core Platform',
            'description' => 'Users, roles, permissions, and base tenant access.',
            'is_core' => true,
            'sort_order' => 10,
            'resources' => ['users', 'roles', 'permissions'],
        ],
        'master_data' => [
            'name' => 'Master Data',
            'description' => 'Brands, categories, VAT, currencies, payment methods and terms.',
            'is_core' => false,
            'sort_order' => 20,
            'resources' => [
                'brands',
                'categories',
                'vat_groups',
                'currencies',
                'payment_methods',
                'payment_terms',
            ],
        ],
        'inventory' => [
            'name' => 'Inventory Management',
            'description' => 'Warehouses, items, units, stock, transfers, and purchase orders.',
            'is_core' => false,
            'sort_order' => 30,
            'resources' => [
                'warehouses',
                'stock',
                'unit_groups',
                'unit_of_measurements',
                'items',
            ],
        ],
        'sales' => [
            'name' => 'Sales / CRM',
            'description' => 'Customers, customer groups, and salesmen.',
            'is_core' => false,
            'sort_order' => 40,
            'resources' => ['customer_groups', 'customers', 'salesmen'],
        ],
        'purchasing' => [
            'name' => 'Purchasing',
            'description' => 'Suppliers and supplier groups.',
            'is_core' => false,
            'sort_order' => 50,
            'resources' => ['supplier_groups', 'suppliers'],
        ],
    ],

    /**
     * Module codes attached automatically when a tenant is created.
     * `core` is always forced on even if omitted here.
     */
    'default_on_create' => ['core'],
];
