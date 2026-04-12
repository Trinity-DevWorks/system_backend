<?php

namespace App\Modules\Rbac\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable(['resource_key', 'resource_label'])]
class Permission extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withPivot(['can_view', 'can_add', 'can_edit', 'can_delete', 'can_import', 'can_export'])
            ->withTimestamps();
    }
}
