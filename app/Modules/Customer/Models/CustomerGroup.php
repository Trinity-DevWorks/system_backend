<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable(['name'])]
class CustomerGroup extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'customer_group_id');
    }
}
