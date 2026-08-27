<?php

declare(strict_types=1);
use App\Modules\Rbac\RbacResourceCatalog;

/**
 * Permission catalog: resource_key => label + applicable actions.
 * Synced into tenant `permissions` table by BootstrapTenantRbac.
 *
 * `actions` must match `check.permission:<resource>,<action>` on tenant routes.
 * The permissions matrix only shows these actions; inapplicable flags are
 * ignored on save and denied by PermissionService.
 *
 * When you add import/export (or any other) routes for a resource, add that
 * action here in the same change. Leaving it off hides the checkbox and
 * makes the new route 403 for every role, including Owner.
 *
 * @see RbacResourceCatalog
 * @see routes/tenant.php
 */
$crud = ['view', 'add', 'edit', 'delete'];
$viewEdit = ['view', 'edit'];

return [
    'actions' => ['view', 'add', 'edit', 'delete', 'import', 'export'],

    'resources' => [
        'notifications' => [
            'label' => 'Notifications',
            'actions' => $crud,
        ],
        'users' => [
            'label' => 'User Management',
            'actions' => $crud,
        ],
        'roles' => [
            'label' => 'Role Management',
            'actions' => $crud,
        ],
        'permissions' => [
            'label' => 'Permission Management',
            'actions' => $viewEdit,
        ],
        'audits' => [
            'label' => 'Audit Log',
            'actions' => ['view', 'export'],
        ],
        'company_profile' => [
            'label' => 'Company Profile Management',
            'actions' => $viewEdit,
        ],
        'company_settings' => [
            'label' => 'Company Settings Management',
            'actions' => $viewEdit,
        ],
        'branches' => [
            'label' => 'Branch Management',
            'actions' => $crud,
        ],

        'brands' => [
            'label' => 'Brand Management',
            'actions' => $crud,
        ],
        'categories' => [
            'label' => 'Category Management',
            'actions' => $crud,
        ],
        'vat_groups' => [
            'label' => 'VAT Group Management',
            'actions' => $crud,
        ],
        'currencies' => [
            'label' => 'Currency Management',
            'actions' => $crud,
        ],
        'payment_methods' => [
            'label' => 'Payment Method Management',
            'actions' => $crud,
        ],
        'payment_terms' => [
            'label' => 'Payment Term Management',
            'actions' => $crud,
        ],
        'warehouses' => [
            'label' => 'Warehouse Management',
            'actions' => $crud,
        ],
        'stock' => [
            'label' => 'Stock Management',
            'actions' => $crud,
        ],
        'salesmen' => [
            'label' => 'Salesman Management',
            'actions' => $crud,
        ],

        'unit_groups' => [
            'label' => 'Unit Group Management',
            'actions' => $crud,
        ],
        'unit_of_measurements' => [
            'label' => 'Unit Of Measurement Management',
            'actions' => $crud,
        ],
        'items' => [
            'label' => 'Item Management',
            'actions' => $crud,
        ],

        'customer_groups' => [
            'label' => 'Customer Group Management',
            'actions' => $crud,
        ],
        'customers' => [
            'label' => 'Customer Management',
            'actions' => $crud,
        ],

        'supplier_groups' => [
            'label' => 'Supplier Group Management',
            'actions' => $crud,
        ],
        'suppliers' => [
            'label' => 'Supplier Management',
            'actions' => $crud,
        ],
    ],
];
