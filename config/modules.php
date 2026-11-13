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
            'description' => 'Users, roles, permissions, company profile, company settings, branches, notifications, and base tenant access.',
            'is_core' => true,
            'sort_order' => 10,
            'resources' => ['users', 'roles', 'permissions', 'company_profile', 'company_settings', 'document_numbering', 'print_settings', 'branches', 'notifications', 'business_reports'],
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
                'item_attributes',
                'price_changes',
                'price_below_cost_override',
            ],
        ],
        'sales' => [
            'name' => 'Sales / CRM',
            'description' => 'Customers, customer groups, salesmen, quotations, sales orders, sales invoices, credit notes, customer receipts, and customer refunds.',
            'is_core' => false,
            'sort_order' => 40,
            'resources' => ['customer_groups', 'customers', 'salesmen', 'sales_quotations', 'sales_orders', 'delivery_notes', 'sales_invoices', 'credit_limit_override', 'discount_override', 'price_lists', 'sales_credit_notes', 'customer_receipts', 'customer_cheques', 'customer_refunds'],
        ],
        'purchasing' => [
            'name' => 'Purchasing',
            'description' => 'Suppliers, supplier groups, purchase invoices, debit notes, supplier payments, and supplier refunds.',
            'is_core' => false,
            'sort_order' => 50,
            'resources' => ['supplier_groups', 'suppliers', 'purchase_invoices', 'landed_costs', 'purchase_debit_notes', 'supplier_payments', 'supplier_refunds'],
        ],
        'accounting' => [
            'name' => 'Accounting',
            'description' => 'Chart of accounts, journal entries, financial statements, VAT, budgets, and fiscal years.',
            'is_core' => false,
            'sort_order' => 60,
            'resources' => ['accounts', 'account_mappings', 'journal_entries', 'accounting_reports', 'vat_settlements', 'currency_revaluations', 'accounting_periods', 'budgets', 'cash_bank_accounts', 'treasury_transfers', 'expense_vouchers', 'recurring_entries', 'deferral_schedules', 'fixed_asset_categories', 'fixed_assets', 'depreciation_runs'],
        ],
    ],

    /**
     * Module codes attached automatically when a tenant is created.
     * `core` is always forced on even if omitted here.
     */
    'default_on_create' => ['core'],
];
