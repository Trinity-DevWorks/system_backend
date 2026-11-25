<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One tenant's usage, as last collected (see TenantUsageService). Not audited: it is recomputed hourly.
 *
 * @property string $tenant_id
 * @property int $active_users
 * @property int $total_users
 * @property int $branches
 * @property int $warehouses
 * @property int $storage_bytes
 * @property int $sales_invoices_month
 * @property int $purchase_invoices_month
 * @property Carbon|null $last_activity_at
 * @property Carbon $computed_at
 */
#[Fillable([
    'tenant_id',
    'active_users',
    'total_users',
    'branches',
    'warehouses',
    'storage_bytes',
    'sales_invoices_month',
    'purchase_invoices_month',
    'last_activity_at',
    'computed_at',
])]
class TenantUsageSnapshot extends Model
{
    use CentralConnection;

    protected $table = 'tenant_usage_snapshots';

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active_users' => 'integer',
            'total_users' => 'integer',
            'branches' => 'integer',
            'warehouses' => 'integer',
            'storage_bytes' => 'integer',
            'sales_invoices_month' => 'integer',
            'purchase_invoices_month' => 'integer',
            'last_activity_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }
}
