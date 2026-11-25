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
 * The platform mailbox (central Settings → Email). One row.
 *
 * @property bool $enabled
 * @property string|null $host
 * @property int $port
 * @property string $encryption
 * @property string|null $username
 * @property string|null $password
 * @property string|null $from_address
 * @property string|null $from_name
 * @property string|null $reply_to
 * @property Carbon|null $verified_at
 */
#[Fillable([
    'enabled',
    'host',
    'port',
    'encryption',
    'username',
    'password',
    'from_address',
    'from_name',
    'reply_to',
    'verified_at',
])]
class PlatformEmailSetting extends Model implements AuditableContract
{
    use Auditable;
    use CentralConnection;

    protected $table = 'platform_email_settings';

    /** @var list<string> */
    protected $hidden = ['password'];

    /**
     * Never write the password into the audit log.
     *
     * @var list<string>
     */
    protected $auditExclude = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'password' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    public static function singleton(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([], [
            'enabled' => false,
            'port' => 587,
            'encryption' => 'tls',
        ]);
    }

    /** Enough is filled in to try sending. */
    public function isComplete(): bool
    {
        return filled($this->host) && filled($this->from_address);
    }

    public function isReady(): bool
    {
        return $this->enabled && $this->isComplete();
    }
}
