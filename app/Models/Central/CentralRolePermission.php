<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class CentralRolePermission extends Model
{
    use CentralConnection;

    protected $table = 'central_role_permissions';

    /** @var list<string> */
    protected $fillable = [
        'role_id', 'permission_id',
        'can_view', 'can_add', 'can_edit', 'can_delete', 'can_import', 'can_export', 'can_reverse',
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
            'can_reverse' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CentralRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(CentralRole::class, 'role_id');
    }

    /**
     * @return BelongsTo<CentralPermission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(CentralPermission::class, 'permission_id');
    }
}
