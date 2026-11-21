<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Modules\CompanySetting\Support\TenantDisplayFormat;

final class LedgerStatementDocument
{
    /**
     * @param  'customer'|'supplier'  $partyKind
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $summaries
     */
    public static function make(
        string $partyKind,
        string $partyName,
        ?string $partyCode,
        ?string $currencyLabel,
        ?string $dateFrom,
        ?string $dateTo,
        bool $showCurrency,
        array $entries,
        array $summaries,
    ): TabularDocument {
        $party = trim(($partyCode !== null && $partyCode !== '' ? $partyCode.' ' : '').$partyName);
        $filters = [
            __('ledger.party_'.$partyKind).': '.$party,
            __('ledger.currency').': '.($currencyLabel !== null && $currencyLabel !== '' ? $currencyLabel : __('ledger.all_currencies')),
        ];
        if ($dateFrom !== null && $dateFrom !== '') {
            $filters[] = __('ledger.date_from').': '.(TenantDisplayFormat::date($dateFrom) ?: $dateFrom);
        }
        if ($dateTo !== null && $dateTo !== '') {
            $filters[] = __('ledger.date_to').': '.(TenantDisplayFormat::date($dateTo) ?: $dateTo);
        }

        $headings = [__('ledger.col_date')];
        if ($showCurrency) {
            $headings[] = __('ledger.col_currency');
        }
        array_push(
            $headings,
            __('ledger.col_type'),
            __('ledger.col_document'),
            __('ledger.col_debit'),
            __('ledger.col_credit'),
            __('ledger.col_balance'),
        );

        $rows = [];
        foreach ($entries as $entry) {
            $symbol = isset($entry['currency_symbol']) ? (string) $entry['currency_symbol'] : null;
            $type = (string) ($entry['reference_type'] ?? '');
            $row = [TenantDisplayFormat::date($entry['transaction_date'] ?? null) ?: '—'];
            if ($showCurrency) {
                $row[] = (string) ($entry['currency_code'] ?? '—');
            }
            $row[] = self::typeLabel($partyKind, $type, (bool) ($entry['reversed'] ?? false));
            $row[] = isset($entry['document_number']) && $entry['document_number'] !== ''
                ? (string) $entry['document_number']
                : '—';
            $row[] = self::money($entry['debit'] ?? null, $symbol);
            $row[] = self::money($entry['credit'] ?? null, $symbol);
            $row[] = self::money($entry['running_balance'] ?? null, $symbol);
            $rows[] = $row;
        }

        $summaryBlocks = [];
        foreach ($summaries as $summary) {
            $symbol = isset($summary['currency_symbol']) ? (string) $summary['currency_symbol'] : null;
            $code = (string) ($summary['currency_code'] ?? $summary['currency_id'] ?? '');
            $summaryBlocks[] = [
                'heading' => $code,
                'lines' => [
                    ['label' => __('ledger.opening_balance'), 'value' => self::money($summary['opening_balance'] ?? null, $symbol)],
                    ['label' => __('ledger.period_debit'), 'value' => self::money($summary['period_debit'] ?? null, $symbol)],
                    ['label' => __('ledger.period_credit'), 'value' => self::money($summary['period_credit'] ?? null, $symbol)],
                    ['label' => __('ledger.closing_balance'), 'value' => self::money($summary['closing_balance'] ?? null, $symbol)],
                ],
            ];
        }

        return new TabularDocument(
            title: $partyKind === 'supplier' ? __('ledger.supplier_title') : __('ledger.customer_title'),
            filename: self::filename($partyKind, $partyCode),
            headings: $headings,
            rows: $rows,
            filters: $filters,
            summaries: $summaryBlocks,
        );
    }

    private static function typeLabel(string $partyKind, string $type, bool $reversed): string
    {
        $key = match ($type) {
            'opening_balance' => 'ledger.type_opening_balance',
            'invoice' => 'ledger.type_invoice',
            'purchase_invoice' => 'ledger.type_purchase_invoice',
            'payment' => $partyKind === 'supplier' ? 'ledger.type_payment_supplier' : 'ledger.type_payment_customer',
            default => 'ledger.type_unknown',
        };
        $label = __($key);

        return $reversed ? $label.' '.__('ledger.reversed') : $label;
    }

    private static function money(mixed $value, ?string $symbol): string
    {
        $formatted = TenantDisplayFormat::money($value ?? '0');
        $mark = $symbol !== null ? trim($symbol) : '';

        return $mark !== '' ? $formatted.$mark : $formatted;
    }

    private static function filename(string $partyKind, ?string $partyCode): string
    {
        $prefix = $partyKind === 'supplier' ? 'supplier-ledger' : 'customer-ledger';
        $slug = $partyCode !== null ? trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $partyCode), '-') : '';

        return $slug !== '' ? $prefix.'-'.$slug : $prefix;
    }
}
