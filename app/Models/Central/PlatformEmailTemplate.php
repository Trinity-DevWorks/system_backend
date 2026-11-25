<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A customised text for a platform email (e.g. `password_reset`) in one language.
 * No row = the default text from lang/{locale}/platform_emails.php.
 *
 * @property string $type
 * @property string $locale
 * @property string $subject
 * @property string $body
 */
#[Fillable(['type', 'locale', 'subject', 'body'])]
class PlatformEmailTemplate extends Model implements AuditableContract
{
    use Auditable;
    use CentralConnection;

    protected $table = 'platform_email_templates';
}
