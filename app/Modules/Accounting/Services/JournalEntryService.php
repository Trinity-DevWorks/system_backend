<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\DTOs\JournalEntryData;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Support\JournalLineRules;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Support\SequentialCodeGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class JournalEntryService
{
    public const RELATIONS = [
        'lines.account:id,code,name,name_ar,type,normal_balance',
        'lines.currency:id,code,name,symbol',
        'branch:id,name',
        'createdByUser:id,name',
        'postedByUser:id,name',
        'reversal:id,entry_number,reversal_of_id',
        'reversalOf:id,entry_number',
    ];

    public function __construct(
        private readonly JournalLineRules $rules,
    ) {}

    /**
     * @param  array{search?: ?string, from?: ?string, to?: ?string, source_type?: ?string, status?: ?string, account_id?: ?int}  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = JournalEntry::query()
            ->with(['branch:id,name', 'createdByUser:id,name'])
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_number');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('entry_number', 'ilike', '%'.$search.'%')
                    ->orWhere('description', 'ilike', '%'.$search.'%');
            });
        }
        if (! empty($filters['from'])) {
            $query->whereDate('entry_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('entry_date', '<=', $filters['to']);
        }
        if (! empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['account_id'])) {
            $accountId = (int) $filters['account_id'];
            $query->whereHas('lines', fn (Builder $q) => $q->where('account_id', $accountId));
        }

        return $query->paginate($perPage);
    }

    public function load(JournalEntry $entry): JournalEntry
    {
        return $entry->load(self::RELATIONS);
    }

    public function createManual(JournalEntryData $data, ?string $userId): JournalEntry
    {
        return DB::transaction(function () use ($data, $userId): JournalEntry {
            $entry = JournalEntry::query()->create([
                'entry_number' => SequentialCodeGenerator::next(JournalEntry::class, 'entry_number', 'JE-'),
                'entry_date' => $data->entryDate,
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'source_id' => null,
                'source_event' => JournalEntry::EVENT_POST,
                'branch_id' => $data->branchId ?? $this->activeBranchId(),
                'description' => $data->description,
                'status' => JournalEntryStatus::Draft,
                'total_debit' => '0',
                'total_credit' => '0',
                'created_by' => $userId,
            ]);

            if ($data->lines !== null) {
                $this->writeLines($entry, $this->rules->prepare($data->lines, true));
            }

            return $this->load($entry->refresh());
        });
    }

    public function updateManual(JournalEntry $entry, JournalEntryData $data): JournalEntry
    {
        return DB::transaction(function () use ($entry, $data): JournalEntry {
            $entry = $this->lockEditable($entry);
            $entry->update([
                'entry_date' => $data->entryDate,
                'description' => $data->description,
                'branch_id' => $data->branchId ?? $entry->branch_id,
            ]);

            if ($data->lines !== null) {
                $this->writeLines($entry, $this->rules->prepare($data->lines, true));
            }

            return $this->load($entry->refresh());
        });
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    public function replaceLines(JournalEntry $entry, array $lines): JournalEntry
    {
        return DB::transaction(function () use ($entry, $lines): JournalEntry {
            $entry = $this->lockEditable($entry);
            $this->writeLines($entry, $this->rules->prepare($lines, true));

            return $this->load($entry->refresh());
        });
    }

    public function post(JournalEntry $entry, ?string $userId): JournalEntry
    {
        return DB::transaction(function () use ($entry, $userId): JournalEntry {
            $entry = $this->lockEditable($entry);
            $this->assertPeriodOpen($this->dateString($entry->entry_date));

            $lines = $entry->lines()->get()->map(fn (JournalEntryLine $line): JournalLineData => new JournalLineData(
                accountId: (int) $line->account_id,
                debit: (string) $line->debit,
                credit: (string) $line->credit,
                currencyId: (int) $line->currency_id,
                exchangeRate: (string) $line->exchange_rate,
                amountCurrency: (string) $line->amount_currency,
                partyType: $line->party_type,
                partyId: $line->party_id,
                description: $line->description,
            ))->all();

            $rows = $this->rules->prepare($lines, true);
            $totals = $this->rules->assertBalanced($rows);

            $entry->update([
                'status' => JournalEntryStatus::Posted,
                'total_debit' => $totals['debit'],
                'total_credit' => $totals['credit'],
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->load($entry->refresh());
        });
    }

    public function reverse(JournalEntry $entry, ?string $userId): JournalEntry
    {
        return DB::transaction(function () use ($entry, $userId): JournalEntry {
            /** @var JournalEntry $entry */
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if (! $entry->isManual()) {
                abort(422, 'Automatic entries are reversed through their source document.', [
                    'X-Error-Code' => 'JOURNAL_ENTRY_AUTOMATIC',
                ]);
            }

            $reversal = $this->reverseEntry($entry, $userId, null, (string) $entry->id);

            return $this->load($reversal);
        });
    }

    public function delete(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $entry = $this->lockEditable($entry, 'JOURNAL_ENTRY_NOT_DELETABLE');
            $entry->delete();
        });
    }

    /**
     * Insert a posted entry for an automatic source. Caller owns the transaction.
     *
     * @param  array{entry_date: string, source_type: string, source_id: ?string, source_event: string, branch_id?: ?int, description?: ?string, reversal_of_id?: ?string}  $header
     * @param  list<JournalLineData>  $lines
     */
    public function createPosted(array $header, array $lines, ?string $userId, bool $allowClosedPeriod = false): JournalEntry
    {
        if (! $allowClosedPeriod) {
            $this->assertPeriodOpen($this->dateString($header['entry_date']));
        }

        $rows = $this->rules->prepare($lines, false);
        $totals = $this->rules->assertBalanced($rows);

        $entry = JournalEntry::query()->create([
            'entry_number' => SequentialCodeGenerator::next(JournalEntry::class, 'entry_number', 'JE-'),
            'entry_date' => $header['entry_date'],
            'source_type' => $header['source_type'],
            'source_id' => $header['source_id'],
            'source_event' => $header['source_event'],
            'reversal_of_id' => $header['reversal_of_id'] ?? null,
            'branch_id' => array_key_exists('branch_id', $header) ? $header['branch_id'] : $this->activeBranchId(),
            'description' => isset($header['description']) ? mb_substr((string) $header['description'], 0, 500) : null,
            'status' => JournalEntryStatus::Posted,
            'total_debit' => $totals['debit'],
            'total_credit' => $totals['credit'],
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => now(),
        ]);
        $this->writeLines($entry, $rows);

        return $entry;
    }

    /**
     * Post a mirror entry (sides swapped) and mark the original reversed. Caller owns the transaction.
     */
    public function reverseEntry(JournalEntry $original, ?string $userId, ?string $date = null, ?string $sourceId = null): JournalEntry
    {
        if ($original->status !== JournalEntryStatus::Posted) {
            abort(422, 'Only posted journal entries can be reversed.', ['X-Error-Code' => 'JOURNAL_ENTRY_NOT_POSTED']);
        }

        $reversalDate = $date ?? Carbon::today()->toDateString();
        $this->assertPeriodOpen($this->dateString($reversalDate));

        $reversal = JournalEntry::query()->create([
            'entry_number' => SequentialCodeGenerator::next(JournalEntry::class, 'entry_number', 'JE-'),
            'entry_date' => $reversalDate,
            'source_type' => $original->source_type,
            'source_id' => $sourceId ?? $original->source_id,
            'source_event' => JournalEntry::EVENT_REVERSE,
            'reversal_of_id' => $original->id,
            'branch_id' => $original->branch_id,
            'description' => mb_substr('Reversal of '.$original->entry_number.($original->description ? ' — '.$original->description : ''), 0, 500),
            'status' => JournalEntryStatus::Posted,
            'total_debit' => $original->total_credit,
            'total_credit' => $original->total_debit,
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => now(),
        ]);

        $rows = [];
        foreach ($original->lines()->get() as $line) {
            $rows[] = [
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'currency_id' => $line->currency_id,
                'exchange_rate' => $line->exchange_rate,
                'amount_currency' => bcmul((string) $line->amount_currency, '-1', 4),
                'party_type' => $line->party_type,
                'party_id' => $line->party_id,
                'description' => $line->description,
                'sort_order' => $line->sort_order,
            ];
        }
        $this->writeLines($reversal, $rows);

        $original->update(['status' => JournalEntryStatus::Reversed]);

        return $reversal;
    }

    /**
     * Remove an automatic entry outright. Only used where the source replaces its own posting (opening balances).
     */
    public function deleteAutomatic(string $sourceType, string $sourceId): void
    {
        $entries = JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderByRaw("CASE WHEN source_event = 'reverse' THEN 0 ELSE 1 END")
            ->get();
        foreach ($entries as $entry) {
            $this->assertPeriodOpen($this->dateString($entry->entry_date));
        }
        foreach ($entries as $entry) {
            $entry->delete();
        }
    }

    /**
     * Reject a posting whose date is on or before the books-closed date.
     * Drafts are not checked here. Year-end close passes `$allowClosedPeriod`
     * because that entry is dated on the locked year end on purpose.
     */
    private function assertPeriodOpen(?string $date): void
    {
        if ($date === null || $date === '') {
            return;
        }

        $closedThrough = CompanySetting::current()->booksClosedThrough();
        if ($closedThrough !== null && $date <= $closedThrough) {
            abort(422, 'Books are closed through '.$closedThrough.'.', [
                'X-Error-Code' => 'PERIOD_LOCKED',
            ]);
        }
    }

    private function dateString(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeLines(JournalEntry $entry, array $rows): void
    {
        JournalEntryLine::query()->where('journal_entry_id', $entry->id)->delete();
        $now = now();
        $insert = array_map(fn (array $row): array => [
            ...$row,
            'journal_entry_id' => $entry->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);
        if ($insert !== []) {
            JournalEntryLine::query()->insert($insert);
        }

        if ($entry->status === JournalEntryStatus::Draft) {
            $totals = $this->rules->totals($rows);
            $entry->update(['total_debit' => $totals['debit'], 'total_credit' => $totals['credit']]);
        }
    }

    private function lockEditable(JournalEntry $entry, string $code = 'JOURNAL_ENTRY_NOT_EDITABLE'): JournalEntry
    {
        /** @var JournalEntry $locked */
        $locked = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
        if (! $locked->isManual() || $locked->status !== JournalEntryStatus::Draft) {
            abort(422, 'Only draft manual journal entries can be changed.', ['X-Error-Code' => $code]);
        }

        return $locked;
    }

    private function activeBranchId(): ?int
    {
        $branchId = request()->attributes->get('active_branch_id');

        return $branchId !== null ? (int) $branchId : null;
    }
}
