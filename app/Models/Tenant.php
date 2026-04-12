<?php

declare(strict_types=1);

namespace App\Models;

use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements AuditableContract, TenantWithDatabase
{
    use Auditable;
    use HasDatabase;
    use HasDomains;

    protected $fillable = [
        'id',
        'name',
        'data',
    ];
}
