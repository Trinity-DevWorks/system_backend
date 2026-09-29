<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Central (platform) RBAC catalog
|--------------------------------------------------------------------------
|
| Resources and actions for central admin routes guarded by
| `check.central.permission:<resource>,<action>` in routes/api.php.
| When adding a central route with a new action, add that action here
| in the same change. Run `php artisan central:sync-rbac` afterwards.
|
*/

$crud = ['view', 'add', 'edit', 'delete'];

return [
    'super_admin_role' => 'Super Admin',

    'resources' => [
        'overview' => ['label' => 'Overview', 'actions' => ['view']],
        'tenants' => ['label' => 'Tenants', 'actions' => $crud],
        'tenant_modules' => ['label' => 'Tenant Modules', 'actions' => ['view', 'edit']],
        'modules' => ['label' => 'Modules Catalog', 'actions' => ['view']],
        'users' => ['label' => 'Central Users', 'actions' => $crud],
        'roles' => ['label' => 'Central Roles', 'actions' => $crud],
        'permissions' => ['label' => 'Central Permissions', 'actions' => ['view', 'edit']],
        'audits' => ['label' => 'Central Audit Log', 'actions' => ['view', 'export']],
    ],
];
