<?php

declare(strict_types=1);

namespace App\Modules\Accounting\DTOs;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Support\SourceDocuments;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;

final class JournalEntryResponseData
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(JournalEntry $entry, ?string $sourceNumber = null): array
    {
        return [
            'id' => (string) $entry->id,
            'entry_number' => (string) $entry->entry_number,
            'entry_date' => $entry->entry_date?->toDateString(),
            'source_type' => (string) $entry->source_type,
            'source_id' => $entry->source_id,
            'source_event' => (string) $entry->source_event,
            'source_number' => $sourceNumber,
            'is_manual' => $entry->isManual(),
            'reversal_of_id' => $entry->reversal_of_id,
            'branch_id' => $entry->branch_id,
            'branch_name' => $entry->relationLoaded('branch') ? $entry->branch?->name : null,
            'description' => $entry->description,
            'status' => $entry->status->value,
            'total_debit' => (string) $entry->total_debit,
            'total_credit' => (string) $entry->total_credit,
            'created_by' => $entry->created_by,
            'created_by_name' => $entry->relationLoaded('createdByUser') ? $entry->createdByUser?->name : null,
            'posted_by' => $entry->posted_by,
            'posted_by_name' => $entry->relationLoaded('postedByUser') ? $entry->postedByUser?->name : null,
            'posted_at' => $entry->posted_at?->toIso8601String(),
            'created_at' => (string) $entry->created_at,
            'updated_at' => (string) $entry->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(JournalEntry $entry): array
    {
        $numbers = SourceDocuments::numbers([[(string) $entry->source_type, $entry->isManual() ? null : $entry->source_id]]);
        $data = self::summary($entry, $numbers[$entry->source_type.':'.$entry->source_id] ?? null);

        $lines = $entry->relationLoaded('lines') ? $entry->lines : $entry->lines()->with(['account', 'currency'])->get();
        $parties = self::partyNames($lines);

        $data['reversal_id'] = $entry->relationLoaded('reversal') ? $entry->reversal?->id : null;
        $data['reversal_number'] = $entry->relationLoaded('reversal') ? $entry->reversal?->entry_number : null;
        $data['reversal_of_number'] = $entry->relationLoaded('reversalOf') ? $entry->reversalOf?->entry_number : null;
        $data['lines'] = $lines->map(fn (JournalEntryLine $line): array => [
            'id' => (int) $line->id,
            'account_id' => (int) $line->account_id,
            'account_code' => $line->account?->code,
            'account_name' => $line->account?->name,
            'account_name_ar' => $line->account?->name_ar,
            'debit' => (string) $line->debit,
            'credit' => (string) $line->credit,
            'currency_id' => (int) $line->currency_id,
            'currency_code' => $line->currency?->code,
            'exchange_rate' => (string) $line->exchange_rate,
            'amount_currency' => (string) $line->amount_currency,
            'party_type' => $line->party_type,
            'party_id' => $line->party_id,
            'party_name' => $line->party_id !== null ? ($parties[$line->party_type.':'.$line->party_id] ?? null) : null,
            'description' => $line->description,
            'sort_order' => (int) $line->sort_order,
        ])->values()->all();

        return $data;
    }

    /**
     * @param  iterable<JournalEntryLine>  $lines
     * @return array<string, string>
     */
    public static function partyNames(iterable $lines): array
    {
        $ids = [JournalEntryLine::PARTY_CUSTOMER => [], JournalEntryLine::PARTY_SUPPLIER => []];
        foreach ($lines as $line) {
            if ($line->party_id !== null && isset($ids[$line->party_type])) {
                $ids[$line->party_type][$line->party_id] = true;
            }
        }

        $out = [];
        if ($ids[JournalEntryLine::PARTY_CUSTOMER] !== []) {
            foreach (Customer::query()->whereIn('id', array_keys($ids[JournalEntryLine::PARTY_CUSTOMER]))->get(['id', 'name']) as $c) {
                $out['customer:'.$c->id] = (string) $c->name;
            }
        }
        if ($ids[JournalEntryLine::PARTY_SUPPLIER] !== []) {
            foreach (Supplier::query()->whereIn('id', array_keys($ids[JournalEntryLine::PARTY_SUPPLIER]))->get(['id', 'name']) as $s) {
                $out['supplier:'.$s->id] = (string) $s->name;
            }
        }

        return $out;
    }
}
