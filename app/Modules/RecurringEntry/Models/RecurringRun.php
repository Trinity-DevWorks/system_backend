<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Models;

use App\Models\User;
use App\Modules\RecurringEntry\Enums\RecurringDocumentType;
use App\Modules\RecurringEntry\Enums\RecurringRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to generate a scheduled occurrence. This row is the log, so it is not audited.
 *
 * @property int $id
 * @property string $recurring_template_id
 * @property Carbon $scheduled_date
 * @property RecurringRunStatus $status
 * @property RecurringDocumentType $document_type
 * @property string|null $document_id
 * @property string|null $document_number
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $triggered_by
 * @property int $attempts
 */
#[Fillable([
    'recurring_template_id', 'scheduled_date', 'status', 'document_type', 'document_id',
    'document_number', 'error_code', 'error_message', 'triggered_by', 'attempts',
])]
class RecurringRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'status' => RecurringRunStatus::class,
            'document_type' => RecurringDocumentType::class,
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RecurringTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringTemplate::class, 'recurring_template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
