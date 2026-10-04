<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Modules\CompanySetting\Enums\DateFormat;
use App\Modules\CompanySetting\Enums\NumberFormat;
use App\Modules\CompanySetting\Enums\PreferredLanguage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform regional settings: central console display and defaults for new tenants.
 *
 * @property PreferredLanguage $preferred_language
 * @property string $timezone
 * @property DateFormat $date_format
 * @property NumberFormat $number_format
 */
#[Fillable([
    'preferred_language',
    'timezone',
    'date_format',
    'number_format',
])]
class PlatformSetting extends Model implements AuditableContract
{
    use Auditable;
    use CentralConnection;

    protected $table = 'platform_settings';

    /**
     * Single row in the central database.
     */
    public static function singleton(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([], [
            'preferred_language' => PreferredLanguage::En,
            'timezone' => 'UTC',
            'date_format' => DateFormat::YmdDash,
            'number_format' => NumberFormat::CommaDot,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_language' => PreferredLanguage::class,
            'date_format' => DateFormat::class,
            'number_format' => NumberFormat::class,
        ];
    }
}
