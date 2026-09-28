<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Central\CentralPermission;
use App\Models\Central\CentralRole;
use App\Models\Central\CentralRolePermission;
use App\Models\User;
use App\Services\Central\CentralPermissionCatalogService;
use App\Services\Central\CentralRoleService;
use App\Services\CentralPermissionService;
use App\Support\CentralRbacResourceCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Syncs config/central_rbac.php into central permissions, ensures the Super Admin
 * system role, and assigns it to central users that have no role yet.
 */
class SyncCentralRbac implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        CentralPermissionService $permissionService,
        CentralPermissionCatalogService $catalogService,
    ): void {
        DB::connection(config('tenancy.database.central_connection'))->transaction(function (): void {
            foreach (CentralRbacResourceCatalog::resourceKeys() as $resourceKey) {
                CentralPermission::query()->updateOrCreate(
                    ['resource_key' => $resourceKey],
                    ['resource_label' => CentralRbacResourceCatalog::label($resourceKey)]
                );
            }

            $superAdmin = CentralRole::query()->firstOrCreate(
                ['name' => (string) config('central_rbac.super_admin_role', 'Super Admin')],
                [
                    'description' => 'Full platform access',
                    'is_active' => true,
                    'is_system' => true,
                ]
            );

            if (! $superAdmin->is_system || ! $superAdmin->is_active) {
                $superAdmin->update(['is_system' => true, 'is_active' => true]);
            }

            foreach (CentralPermission::query()->get() as $permission) {
                CentralRolePermission::query()->updateOrCreate(
                    ['role_id' => $superAdmin->id, 'permission_id' => $permission->id],
                    CentralRbacResourceCatalog::clampFlags((string) $permission->resource_key, [
                        'can_view' => true,
                        'can_add' => true,
                        'can_edit' => true,
                        'can_delete' => true,
                        'can_import' => true,
                        'can_export' => true,
                        'can_reverse' => true,
                    ])
                );
            }

            User::query()
                ->whereNull('central_role_id')
                ->update(['central_role_id' => $superAdmin->id]);
        });

        $permissionService->invalidateCacheForAllUsers();
        $catalogService->forget();
        CentralRoleService::forgetListCache();
    }
}
