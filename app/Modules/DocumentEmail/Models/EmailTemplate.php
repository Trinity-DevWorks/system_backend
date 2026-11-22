<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A company's own subject / body for one document type and language.
 * No row = the default text from lang/{locale}/document_emails.php.
 *
 * @property int $id
 * @property string $document_type
 * @property string $locale
 * @property string $subject
 * @property string $body
 */
#[Fillable(['document_type', 'locale', 'subject', 'body'])]
class EmailTemplate extends Model implements AuditableContract
{
    use Auditable;
}
