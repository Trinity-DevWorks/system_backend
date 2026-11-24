<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform identity: the product name and logo shown on every host.
 *
 * @property string $name
 * @property string|null $logo_path
 * @property string|null $logo_mime_type
 * @property Carbon|null $logo_updated_at
 */
#[Fillable(['name', 'logo_path', 'logo_mime_type', 'logo_updated_at'])]
class PlatformBranding extends Model implements AuditableContract
{
    use Auditable;
    use CentralConnection;

    public const DEFAULT_NAME = 'MEASolutions';

    protected $table = 'platform_branding';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'logo_updated_at' => 'datetime',
        ];
    }

    /**
     * Single row in the central database.
     */
    public static function singleton(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([], ['name' => self::DEFAULT_NAME]);
    }

    public function hasLogo(): bool
    {
        return is_string($this->logo_path) && $this->logo_path !== '';
    }
}
