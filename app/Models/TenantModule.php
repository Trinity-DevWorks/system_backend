<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class TenantModule extends Model
{
    use CentralConnection;

    protected $table = 'tenant_modules';

    protected $fillable = [
        'tenant_id',
        'module_code',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_code', 'code');
    }
}
