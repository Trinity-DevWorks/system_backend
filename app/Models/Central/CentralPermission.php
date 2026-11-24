<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property-read object{
 *     can_view: bool,
 *     can_add: bool,
 *     can_edit: bool,
 *     can_delete: bool,
 *     can_import: bool,
 *     can_export: bool,
 *     can_reverse: bool
 * } $pivot
 */
#[Fillable(['resource_key', 'resource_label'])]
class CentralPermission extends Model implements AuditableContract
{
    use Auditable;
    use CentralConnection;

    protected $table = 'central_permissions';

    /**
     * @return BelongsToMany<CentralRole, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(CentralRole::class, 'central_role_permissions', 'permission_id', 'role_id')
            ->withPivot(['can_view', 'can_add', 'can_edit', 'can_delete', 'can_import', 'can_export', 'can_reverse'])
            ->withTimestamps();
    }
}
