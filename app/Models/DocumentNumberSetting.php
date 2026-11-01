<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Number format for one document type. Missing rows mean "use the defaults".
 *
 * @property int $id
 * @property string $document_type
 * @property string $prefix
 * @property bool $include_branch
 * @property bool $include_year
 * @property int $padding
 * @property int $start_number
 */
#[Fillable(['document_type', 'prefix', 'include_branch', 'include_year', 'padding', 'start_number'])]
class DocumentNumberSetting extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'include_branch' => 'boolean',
            'include_year' => 'boolean',
            'padding' => 'integer',
            'start_number' => 'integer',
        ];
    }
}
