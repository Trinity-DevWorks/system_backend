<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Support;

use App\Models\User;
use App\Modules\Accounting\DTOs\JournalEntryData;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\RecurringEntry\Enums\RateMode;
use App\Modules\RecurringEntry\Models\RecurringTemplate;
use App\Modules\RecurringEntry\Models\RecurringTemplateLine;
use App\Modules\Treasury\Models\ExpenseVoucher;
use App\Modules\Treasury\Services\ExpenseVoucherService;
use App\Support\ExchangeRateSnapshot;
use Illuminate\Support\Carbon;

/**
 * Turns one template occurrence into a draft journal entry or expense voucher.
 * Posting, when auto_post is on, is the caller's job so a failure rolls the draft back.
 */
final class RecurringDocumentBuilder
{
    public function __construct(
        private readonly ExchangeRateService $exchangeRateService,
        private readonly JournalEntryService $journalEntries,
        private readonly ExpenseVoucherService $expenseVouchers,
    ) {}

    public function journalEntry(RecurringTemplate $template, Carbon $date, ?string $actorId): JournalEntry
    {
        $template->loadMissing('lines');
        $rate = $this->rate($template);
        $rows = [];
        foreach ($template->lines as $line) {
            $rows[] = $this->journalRow($template, $line, $date, $rate);
        }
        // A foreign template can be balanced in document currency and off by 0.0001
        // in the primary currency. The dust goes on the largest line of the smaller
        // side. Manual entries do not grow a Rounding line.
        $rows = $this->absorbRounding($rows);

        $description = DescriptionPlaceholders::render($template->description ?: $template->name, $date);
        $entry = $this->journalEntries->createManual(new JournalEntryData(
            entryDate: $date->toDateString(),
            description: $description,
            branchId: (int) $template->branch_id,
            lines: array_map(fn (array $row): JournalLineData => new JournalLineData(
                accountId: $row['account_id'],
                debit: $row['debit'],
                credit: $row['credit'],
                currencyId: (int) $template->currency_id,
                exchangeRate: $rate,
                amountCurrency: $row['amount_currency'],
                partyType: $row['party_type'],
                partyId: $row['party_id'],
                description: $row['description'],
            ), $rows),
        ), $this->actor($actorId));
        $entry->update(['recurring_template_id' => $template->id]);

        return $entry->refresh();
    }

    public function actorId(?string $userId): ?string
    {
        return $this->actor($userId);
    }

    public function expenseVoucher(RecurringTemplate $template, Carbon $date, ?string $actorId): ExpenseVoucher
    {
        $template->loadMissing('lines');
        $lines = [];
        foreach ($template->lines as $line) {
            $lines[] = [
                'account_id' => (int) $line->account_id,
                'description' => DescriptionPlaceholders::render($line->description ?: $template->description, $date),
                'amount' => (string) $line->amount,
                'vat_group_id' => $line->vat_group_id,
            ];
        }
        $notes = DescriptionPlaceholders::render($template->notes, $date);

        $voucher = $this->expenseVouchers->create([
            'voucher_date' => $date->toDateString(),
            'branch_id' => (int) $template->branch_id,
            'cash_bank_account_id' => (int) $template->cash_bank_account_id,
            'exchange_rate' => $template->rate_mode === RateMode::Fixed ? (string) $template->fixed_exchange_rate : null,
            'payee' => $template->payee,
            'supplier_id' => $template->supplier_id,
            'reference' => $template->reference,
            'notes' => $notes,
            'lines' => $lines,
        ], $this->actor($actorId));
        $voucher->update(['recurring_template_id' => $template->id]);

        return $voucher->refresh();
    }

    /**
     * ExchangeRateService stores the current rate only, so a catch-up run uses
     * today's rate unless the template fixes one.
     */
    public function rate(RecurringTemplate $template): string
    {
        $provided = $template->rate_mode === RateMode::Fixed ? $template->fixed_exchange_rate : null;

        return ExchangeRateSnapshot::resolve(
            $this->exchangeRateService,
            (int) $template->currency_id,
            $provided,
            'JOURNAL',
        );
    }

    /**
     * @return array{account_id: int, debit: string, credit: string, amount_currency: string, party_type: ?string, party_id: ?string, description: ?string}
     */
    private function journalRow(RecurringTemplate $template, RecurringTemplateLine $line, Carbon $date, string $rate): array
    {
        $documentDebit = JournalMath::round((string) $line->debit);
        $documentCredit = JournalMath::round((string) $line->credit);
        $primaryDebit = JournalMath::isPositive($documentDebit)
            ? JournalMath::round(ExchangeRateSnapshot::toPrimary($documentDebit, $rate, JournalMath::WORK_SCALE))
            : '0.0000';
        $primaryCredit = JournalMath::isPositive($documentCredit)
            ? JournalMath::round(ExchangeRateSnapshot::toPrimary($documentCredit, $rate, JournalMath::WORK_SCALE))
            : '0.0000';

        return [
            'account_id' => (int) $line->account_id,
            'debit' => JournalMath::store($primaryDebit),
            'credit' => JournalMath::store($primaryCredit),
            'amount_currency' => JournalMath::store(JournalMath::sub($documentDebit, $documentCredit)),
            'party_type' => $line->party_type,
            'party_id' => $line->party_id,
            'description' => DescriptionPlaceholders::render($line->description ?: $template->description, $date),
        ];
    }

    /**
     * @param  list<array{account_id: int, debit: string, credit: string, amount_currency: string, party_type: ?string, party_id: ?string, description: ?string}>  $rows
     * @return list<array{account_id: int, debit: string, credit: string, amount_currency: string, party_type: ?string, party_id: ?string, description: ?string}>
     */
    private function absorbRounding(array $rows): array
    {
        $debit = '0';
        $credit = '0';
        foreach ($rows as $row) {
            $debit = JournalMath::add($debit, $row['debit']);
            $credit = JournalMath::add($credit, $row['credit']);
        }
        $diff = JournalMath::sub($debit, $credit);
        if (JournalMath::isZero($diff)) {
            return $rows;
        }

        $side = JournalMath::isPositive($diff) ? 'credit' : 'debit';
        $delta = JournalMath::abs($diff);
        $target = null;
        $largest = '0';
        foreach ($rows as $index => $row) {
            if (JournalMath::compare($row[$side], $largest) > 0) {
                $largest = $row[$side];
                $target = $index;
            }
        }
        if ($target === null) {
            return $rows;
        }
        $rows[$target][$side] = JournalMath::store(JournalMath::add($rows[$target][$side], $delta));

        return $rows;
    }

    private function actor(?string $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        return User::query()->whereKey($userId)->where('is_active', true)->exists() ? $userId : null;
    }
}
