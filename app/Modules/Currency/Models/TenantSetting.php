<?php

declare(strict_types=1);

namespace App\Modules\Currency\Models;

use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    protected $table = 'tenant_settings';

    protected $fillable = [
        'primary_currency_id',
    ];

    /**
     * Single row per tenant database.
     */
    public static function singleton(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([], []);
    }
}
