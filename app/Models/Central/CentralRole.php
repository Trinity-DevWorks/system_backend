<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable(['name', 'description', 'is_active', 'is_system', 'created_by'])]
class CentralRole extends Model implements AuditableContract
{
    use Auditable;
    use CentralConnection;

    protected $table = 'central_roles';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_system
            && $this->name === (string) config('central_rbac.super_admin_role', 'Super Admin');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'central_role_id');
    }

    /**
     * @return BelongsToMany<CentralPermission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(CentralPermission::class, 'central_role_permissions', 'role_id', 'permission_id')
            ->withPivot(['can_view', 'can_add', 'can_edit', 'can_delete', 'can_import', 'can_export', 'can_reverse'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
