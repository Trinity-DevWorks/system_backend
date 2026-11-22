<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The company's own mailbox (SMTP) used to email documents (singleton).
 *
 * @property int $id
 * @property bool $enabled
 * @property string|null $host
 * @property int $port
 * @property string $encryption tls | ssl | none
 * @property string|null $username
 * @property string|null $password decrypted on read
 * @property string|null $from_address
 * @property string|null $from_name
 * @property string|null $reply_to
 * @property bool $bcc_sender
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
    'bcc_sender',
    'verified_at',
])]
class EmailSetting extends Model implements AuditableContract
{
    use Auditable;

    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** Ports a company mailbox may use. Anything else is refused (keeps SMTP away from internal services). */
    public const ALLOWED_PORTS = [25, 465, 587, 2525];

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
            'bcc_sender' => 'boolean',
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
            'bcc_sender' => false,
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
