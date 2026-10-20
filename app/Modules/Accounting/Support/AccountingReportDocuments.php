<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Branch\Models\Branch;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Support\TenantDisplayFormat;
use App\Support\Export\TabularDocument;

final class AccountingReportDocuments
{
    /**
     * @param  array{rows: list<array<string, mixed>>, totals: array<string, string>, from: ?string, to: ?string, level: ?int, branch_id?: ?int}  $report
     */
    public static function trialBalance(array $report): TabularDocument
    {
        $symbol = self::primarySymbol();
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = [
                (string) $row['code'],
                self::accountName($row),
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

        $filters = [...self::dateFilters($report['from'], $report['to']), ...self::branchFilter($report)];
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

        $filters = [
            __('accounting.col_account').': '.$account['code'].' '.self::accountName($account),
            ...self::dateFilters($ledger['from'], $ledger['to']),
            ...self::branchFilter($ledger),
        ];

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
     * @param  array<string, mixed>  $report
     */
    public static function incomeStatement(array $report): TabularDocument
    {
        $symbol = self::primarySymbol();
        $comparing = ($report['compare'] ?? 'none') !== 'none';
        $rows = [];
        foreach ($report['sections'] as $section) {
            $rows[] = self::statementRow(self::sectionLabel((string) $section['key']), $section, $symbol, $comparing);
            foreach ($section['rows'] ?? [] as $row) {
                $rows[] = self::statementRow(self::indent((string) $row['code'].' '.self::accountName($row), (int) $row['depth']), $row, $symbol, $comparing);
            }
        }
        foreach ($report['unclassified']['rows'] ?? [] as $index => $row) {
            if ($index === 0) {
                $rows[] = [__('accounting.unclassified'), '', $comparing ? '' : null, $comparing ? '' : null];
            }
            $rows[] = self::statementRow(self::indent((string) $row['code'].' '.self::accountName($row), (int) $row['depth']), $row, $symbol, $comparing);
        }

        return new TabularDocument(
            title: __('accounting.income_statement_title'),
            filename: 'income-statement',
            headings: self::statementHeadings($comparing),
            rows: self::cleanRows($rows, $comparing),
            filters: [
                ...self::reportFilters($report['period']['from'] ?? null, $report['period']['to'] ?? null, $report['compare_period'] ?? null),
                ...self::branchFilter($report),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function balanceSheet(array $report): TabularDocument
    {
        $symbol = self::primarySymbol();
        $comparing = ($report['compare_as_of'] ?? null) !== null;
        $rows = [];
        foreach (['assets' => 'assets_title', 'equity_and_liabilities' => 'equity_and_liabilities_title'] as $side => $titleKey) {
            $rows[] = [__('accounting.'.$titleKey), '', $comparing ? '' : null, $comparing ? '' : null];
            foreach ($report[$side]['sections'] as $section) {
                $rows[] = self::statementRow(self::sectionLabel((string) $section['key']), $section, $symbol, $comparing);
                foreach ($section['rows'] ?? [] as $row) {
                    $label = ! empty($row['computed'])
                        ? self::sectionLabel((string) $row['key'])
                        : self::indent((string) $row['code'].' '.self::accountName($row), (int) $row['depth']);
                    $rows[] = self::statementRow($label, $row, $symbol, $comparing);
                }
            }
            $sideTotal = (string) $report[$side]['total'];
            $sideCompare = $report[$side]['compare_total'];
            $rows[] = self::statementRow(__('accounting.total'), [
                'amount' => $sideTotal,
                'compare_amount' => $sideCompare,
                'change' => $comparing ? JournalMath::store(JournalMath::sub($sideTotal, $sideCompare ?? '0')) : null,
            ], $symbol, $comparing);
        }
        if (($report['unclassified']['rows'] ?? []) !== []) {
            $rows[] = [__('accounting.unclassified'), '', $comparing ? '' : null, $comparing ? '' : null];
            foreach ($report['unclassified']['rows'] as $row) {
                $rows[] = self::statementRow((string) $row['code'].' '.self::accountName($row), $row, $symbol, $comparing);
            }
        }

        $filters = [
            __('accounting.as_of').': '.(TenantDisplayFormat::date((string) $report['as_of']) ?: (string) $report['as_of']),
        ];
        if ($comparing) {
            $filters[] = __('accounting.compare_as_of').': '.(TenantDisplayFormat::date((string) $report['compare_as_of']) ?: (string) $report['compare_as_of']);
        }
        $filters = [...self::headerFilters(), ...$filters];

        return new TabularDocument(
            title: __('accounting.balance_sheet_title'),
            filename: 'balance-sheet',
            headings: self::statementHeadings($comparing),
            rows: self::cleanRows($rows, $comparing),
            filters: $filters,
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function vatReport(array $report): TabularDocument
    {
        $symbol = self::primarySymbol();
        $rows = [];
        foreach (['output' => 'output_vat', 'input' => 'input_vat'] as $key => $totalKey) {
            $rows[] = [__('accounting.'.$key.'_vat_title'), '', ''];
            foreach ($report[$key] as $group) {
                $rows[] = [
                    __('accounting.tax_rate').' '.$group['tax_rate'],
                    self::money($group['taxable_amount'], $symbol),
                    self::money($group['vat'], $symbol),
                ];
            }
            $rows[] = ['', __('accounting.total'), self::money($report[$totalKey], $symbol)];
        }
        $rows[] = [__('accounting.vat_net'), '', self::money($report['net'], $symbol)];

        return new TabularDocument(
            title: __('accounting.vat_report_title'),
            filename: 'vat-report',
            headings: [__('accounting.col_description'), __('accounting.taxable_amount'), __('accounting.vat_amount')],
            rows: $rows,
            filters: self::reportFilters($report['from'] ?? null, $report['to'] ?? null, null),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string|null>
     */
    private static function statementRow(string $label, array $values, string $symbol, bool $comparing): array
    {
        $row = [$label, self::money($values['amount'] ?? '0', $symbol)];
        if ($comparing) {
            $row[] = self::money($values['compare_amount'] ?? '0', $symbol);
            $row[] = self::money($values['change'] ?? '0', $symbol);
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    private static function statementHeadings(bool $comparing): array
    {
        $headings = [__('accounting.col_description'), __('accounting.col_amount')];
        if ($comparing) {
            $headings[] = __('accounting.col_compare');
            $headings[] = __('accounting.col_change');
        }

        return $headings;
    }

    /**
     * Drop the null padding used while a comparison column is off.
     *
     * @param  list<list<string|null>>  $rows
     * @return list<list<string|null>>
     */
    private static function cleanRows(array $rows, bool $comparing): array
    {
        if ($comparing) {
            return $rows;
        }

        return array_map(fn (array $row): array => array_values(array_filter(
            $row,
            fn (mixed $cell): bool => $cell !== null,
        )), $rows);
    }

    private static function sectionLabel(string $key): string
    {
        $translated = __('accounting.section_'.$key);

        return $translated === 'accounting.section_'.$key ? $key : $translated;
    }

    private static function indent(string $label, int $depth): string
    {
        return str_repeat('  ', max(0, $depth - 1)).$label;
    }

    /**
     * @param  array{from?: string, to?: string}|null  $compare
     * @return list<string>
     */
    private static function reportFilters(?string $from, ?string $to, ?array $compare): array
    {
        $filters = self::headerFilters();
        $filters = [...$filters, ...self::dateFilters($from, $to)];
        if ($compare !== null) {
            $filters[] = __('accounting.compare_period').': '
                .(TenantDisplayFormat::date((string) ($compare['from'] ?? '')) ?: (string) ($compare['from'] ?? ''))
                .' – '
                .(TenantDisplayFormat::date((string) ($compare['to'] ?? '')) ?: (string) ($compare['to'] ?? ''));
        }

        return $filters;
    }

    /**
     * @return list<string>
     */
    private static function headerFilters(): array
    {
        $filters = [];
        $company = trim((string) (tenant('name') ?? ''));
        if ($company !== '') {
            $filters[] = $company;
        }
        $generated = TenantDisplayFormat::dateTime(now()) ?: now()->format('Y-m-d H:i');
        $filters[] = __('accounting.generated_at').': '.$generated;

        return $filters;
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

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private static function branchFilter(array $report): array
    {
        $id = $report['branch_id'] ?? null;
        if ($id === null || $id === '') {
            return [];
        }

        $name = Branch::query()->whereKey((int) $id)->value('name');
        if ($name === null || $name === '') {
            return [];
        }

        return [__('accounting.branch').': '.$name];
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

    /**
     * Arabic exports use the Arabic account name when one has been filled in.
     *
     * @param  array<string, mixed>  $row
     */
    private static function accountName(array $row): string
    {
        if (app()->getLocale() === 'ar') {
            $arabic = trim((string) ($row['name_ar'] ?? ''));
            if ($arabic !== '') {
                return $arabic;
            }
        }

        return (string) ($row['name'] ?? '');
    }
}
