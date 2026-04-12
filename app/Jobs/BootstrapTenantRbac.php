<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Models\RolePermission;
use App\Services\PermissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class BootstrapTenantRbac implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected TenantWithDatabase $tenant,
        protected int $ownerUserId
    ) {}

    public function handle(PermissionService $permissionService): void
    {
        $ownerUserId = $this->ownerUserId;

        $this->tenant->run(function () use ($ownerUserId, $permissionService): void {
            $resources = config('rbac.resources', []);

            foreach ($resources as $resourceKey => $label) {
                Permission::query()->firstOrCreate(
                    ['resource_key' => $resourceKey],
                    ['resource_label' => is_string($label) ? $label : (string) $resourceKey]
                );
            }

            $ownerRole = Role::query()->firstOrCreate(
                ['name' => 'Owner'],
                [
                    'description' => 'Full access',
                    'active' => true,
                ]
            );

            $adminRole = Role::query()->firstOrCreate(
                ['name' => 'Admin'],
                [
                    'description' => 'Administrative access',
                    'active' => true,
                ]
            );

            $full = [
                'can_view' => true,
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_import' => true,
                'can_export' => true,
            ];

            $permissions = Permission::query()->get();

            foreach ($permissions as $permission) {
                RolePermission::query()->updateOrCreate(
                    [
                        'role_id' => $ownerRole->id,
                        'permission_id' => $permission->id,
                    ],
                    $full
                );

                RolePermission::query()->updateOrCreate(
                    [
                        'role_id' => $adminRole->id,
                        'permission_id' => $permission->id,
                    ],
                    $full
                );
            }

            $owner = User::query()->find($ownerUserId);
            if ($owner) {
                $owner->update(['role_id' => $ownerRole->id]);
                $permissionService->invalidateCacheForUser($owner->fresh());
            }
        });
    }
}
