<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Support\TenantDisplayFormat;
use App\Support\Export\TabularDocument;

final class AccountingReportDocuments
{
    /**
     * @param  array{rows: list<array<string, mixed>>, totals: array<string, string>, from: ?string, to: ?string, level: ?int}  $report
     */
    public static function trialBalance(array $report): TabularDocument
    {
        $symbol = self::primarySymbol();
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = [
                (string) $row['code'],
                (string) $row['name'],
                self::money($row['opening_debit'], $symbol),
                self::money($row['opening_credit'], $symbol),
                self::money($row['period_debit'], $symbol),
                self::money($row['period_credit'], $symbol),
                self::money($row['closing_debit'], $symbol),
                self::money($row['closing_credit'], $symbol),
            ];
        }
        $t = $report['totals'];
        $rows[] = [
            '',
            __('accounting.totals'),
            self::money($t['opening_debit'], $symbol),
            self::money($t['opening_credit'], $symbol),
            self::money($t['period_debit'], $symbol),
            self::money($t['period_credit'], $symbol),
            self::money($t['closing_debit'], $symbol),
            self::money($t['closing_credit'], $symbol),
        ];

        $filters = self::dateFilters($report['from'], $report['to']);
        if ($report['level'] !== null) {
            $filters[] = __('accounting.level').': '.$report['level'];
        }

        return new TabularDocument(
            title: __('accounting.trial_balance_title'),
            filename: 'trial-balance',
            headings: [
                __('accounting.col_code'),
                __('accounting.col_account'),
                __('accounting.col_opening_debit'),
                __('accounting.col_opening_credit'),
                __('accounting.col_period_debit'),
                __('accounting.col_period_credit'),
                __('accounting.col_closing_debit'),
                __('accounting.col_closing_credit'),
            ],
            rows: $rows,
            filters: $filters,
        );
    }

    /**
     * @param  array<string, mixed>  $ledger
     */
    public static function accountLedger(array $ledger): TabularDocument
    {
        $account = $ledger['account'];
        $symbol = self::primarySymbol();
        $rows = [];
        foreach ($ledger['lines'] as $line) {
            $rows[] = [
                TenantDisplayFormat::date($line['entry_date']) ?: (string) $line['entry_date'],
                (string) $line['entry_number'],
                self::sourceLabel((string) $line['source_type'], (string) $line['source_event'], $line['source_number']),
                trim(((string) ($line['party_name'] ?? '')).' '.((string) ($line['description'] ?? ''))),
                self::money($line['debit'], $symbol),
                self::money($line['credit'], $symbol),
                self::money($line['balance'], $symbol),
            ];
        }

        $filters = [__('accounting.col_account').': '.$account['code'].' '.$account['name'], ...self::dateFilters($ledger['from'], $ledger['to'])];

        return new TabularDocument(
            title: __('accounting.account_ledger_title'),
            filename: 'account-ledger-'.preg_replace('/[^0-9]+/', '', (string) $account['code']),
            headings: [
                __('accounting.col_date'),
                __('accounting.col_entry'),
                __('accounting.col_source'),
                __('accounting.col_description'),
                __('accounting.col_debit'),
                __('accounting.col_credit'),
                __('accounting.col_balance'),
            ],
            rows: $rows,
            filters: $filters,
            summaries: [[
                'heading' => (string) $account['code'],
                'lines' => [
                    ['label' => __('accounting.opening_balance'), 'value' => self::money($ledger['opening_balance'], $symbol)],
                    ['label' => __('accounting.period_debit'), 'value' => self::money($ledger['period_debit'], $symbol)],
                    ['label' => __('accounting.period_credit'), 'value' => self::money($ledger['period_credit'], $symbol)],
                    ['label' => __('accounting.closing_balance'), 'value' => self::money($ledger['closing_balance'], $symbol)],
                ],
            ]],
        );
    }

    /**
     * @return list<string>
     */
    private static function dateFilters(?string $from, ?string $to): array
    {
        $out = [];
        if ($from !== null && $from !== '') {
            $out[] = __('accounting.date_from').': '.(TenantDisplayFormat::date($from) ?: $from);
        }
        if ($to !== null && $to !== '') {
            $out[] = __('accounting.date_to').': '.(TenantDisplayFormat::date($to) ?: $to);
        }

        return $out;
    }

    private static function sourceLabel(string $type, string $event, mixed $number): string
    {
        $key = 'accounting.source_'.$type;
        $label = __($key);
        if ($label === $key) {
            $label = $type;
        }
        if ($number !== null && $number !== '') {
            $label .= ' '.$number;
        }

        return $event === 'reverse' ? $label.' ('.__('accounting.reversal').')' : $label;
    }

    private static function money(mixed $value, string $symbol): string
    {
        $formatted = TenantDisplayFormat::money($value ?? '0');
        if ($formatted === '' || $symbol === '') {
            return $formatted;
        }

        return $formatted.' '.$symbol;
    }

    private static function primarySymbol(): string
    {
        return trim((string) (CompanySetting::current()->primaryCurrency?->symbol ?? ''));
    }
}
