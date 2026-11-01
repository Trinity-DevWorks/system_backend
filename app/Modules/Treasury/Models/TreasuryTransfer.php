<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Modules\Branch\Support\HasDocumentBranch;
use App\Modules\Treasury\Enums\TreasuryDocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Money moved between two cash/bank accounts (possibly in different currencies).
 *
 * @property string $id
 * @property int $branch_id
 * @property string|null $transfer_number
 * @property Carbon $transfer_date
 * @property int $from_account_id
 * @property int $to_account_id
 * @property string $amount_from
 * @property string $amount_to
 * @property string $exchange_rate
 * @property string $exchange_rate_to
 * @property TreasuryDocumentStatus $status
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'branch_id', 'transfer_number', 'transfer_date', 'from_account_id', 'to_account_id', 'amount_from', 'amount_to',
    'exchange_rate', 'exchange_rate_to', 'reference', 'notes', 'status', 'created_by', 'posted_by', 'posted_at',
])]
class TreasuryTransfer extends Model implements AuditableContract
{
    use Auditable;
    use HasDocumentBranch;
    use HasUuids;

    public const REFERENCE_TYPE = 'treasury_transfer';

    /**
     * No warehouse: the branch comes from the user's active branch.
     *
     * @return array<string, string|null>
     */
    public function documentBranchColumns(): array
    {
        return ['branch_id' => null];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'amount_from' => 'decimal:4',
            'amount_to' => 'decimal:4',
            'exchange_rate' => 'decimal:12',
            'exchange_rate_to' => 'decimal:12',
            'status' => TreasuryDocumentStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'from_account_id');
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'to_account_id');
    }
}
