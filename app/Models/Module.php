<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Module extends Model
{
    use CentralConnection;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_core',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(
            Tenant::class,
            'tenant_modules',
            'module_code',
            'tenant_id',
            'code',
            'id'
        )->withTimestamps();
    }
}
