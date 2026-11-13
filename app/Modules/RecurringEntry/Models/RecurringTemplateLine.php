<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $recurring_template_id
 * @property int $account_id
 * @property string|null $description
 * @property string $debit
 * @property string $credit
 * @property string $amount
 * @property int|null $vat_group_id
 * @property string|null $party_type
 * @property string|null $party_id
 * @property int $sort_order
 */
#[Fillable([
    'recurring_template_id', 'account_id', 'description', 'debit', 'credit', 'amount',
    'vat_group_id', 'party_type', 'party_id', 'sort_order',
])]
class RecurringTemplateLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'amount' => 'decimal:4',
            'account_id' => 'integer',
            'vat_group_id' => 'integer',
            'sort_order' => 'integer',
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
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<VatGroup, $this>
     */
    public function vatGroup(): BelongsTo
    {
        return $this->belongsTo(VatGroup::class);
    }
}
