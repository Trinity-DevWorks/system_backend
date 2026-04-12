<?php

namespace App\Modules\Rbac\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolePermission extends Model
{
    protected $table = 'role_permissions';

    /** @var list<string> */
    protected $fillable = [
        'role_id', 'permission_id',
        'can_view', 'can_add', 'can_edit', 'can_delete', 'can_import', 'can_export',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_add' => 'boolean',
            'can_edit' => 'boolean',
            'can_delete' => 'boolean',
            'can_import' => 'boolean',
            'can_export' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
