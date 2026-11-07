<?php

declare(strict_types=1);

namespace App\Support\DocumentPdf;

use BackedEnum;

final class DocumentPdfSupport
{
    public static function watermark(BackedEnum|string|null $status): string
    {
        $value = $status instanceof BackedEnum ? (string) $status->value : strtolower((string) $status);

        return match ($value) {
            'draft' => 'DRAFT',
            'reversed' => 'REVERSED',
            default => '',
        };
    }

    /**
     * @param  list<BackedEnum|string>  $allowed
     */
    public static function assertPrintableStatus(
        BackedEnum|string|null $status,
        array $allowed,
        string $message,
        string $errorCode,
    ): void {
        foreach ($allowed as $case) {
            if ($status === $case) {
                return;
            }
        }

        abort(422, $message, ['X-Error-Code' => $errorCode]);
    }

    /**
     * @param  iterable<int, object>  $lines
     * @return list<array<string, string>>
     */
    public static function commercialLines(iterable $lines, DocumentMoney $money, bool $includeTax = true): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $item = $line->item ?? null;
            $uom = $line->itemUom?->uom?->code
                ?? $line->itemUom?->uom?->name
                ?? '—';
            $description = trim((string) ($line->description ?? ''));
            if ($description === '') {
                $description = (string) ($item?->name ?? '—');
            }

            $row = [
                'code' => $money->dash($item?->item_code ?? null),
                'description' => $money->dash($description),
                'qty' => $money->quantity($line->quantity ?? null),
                'uom' => $money->dash($uom === '—' ? null : $uom),
                'unit_price' => isset($line->unit_price) ? $money->money($line->unit_price) : '—',
                'discount' => isset($line->discount_amount) ? $money->money($line->discount_amount) : '—',
                'line_total' => isset($line->line_total) ? $money->money($line->line_total) : '—',
            ];
            if ($includeTax) {
                $row['tax'] = isset($line->tax_amount) ? $money->money($line->tax_amount) : '—';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public static function commercialColumns(bool $includeTax = true): array
    {
        $cols = ['code', 'description', 'qty', 'uom', 'unit_price', 'discount', 'line_total'];
        if ($includeTax) {
            array_splice($cols, 6, 0, ['tax']);
        }

        return $cols;
    }

    /**
     * @return list<array{label: string, value: string, emphasis?: bool}>
     */
    public static function commercialTotals(
        object $doc,
        DocumentMoney $money,
        array $labels,
        bool $includeSettlement = false,
    ): array {
        $rows = [
            ['label' => $labels['subtotal'], 'value' => $money->money($doc->subtotal ?? 0)],
            ['label' => $labels['discount_total'], 'value' => $money->money($doc->discount_total ?? 0)],
            ['label' => $labels['tax_total'], 'value' => $money->money($doc->tax_total ?? 0)],
        ];
        if (isset($doc->adjustment) && (string) $doc->adjustment !== '0.0000' && (float) $doc->adjustment != 0.0) {
            $rows[] = ['label' => $labels['adjustment'], 'value' => $money->money($doc->adjustment)];
        }
        $rows[] = ['label' => $labels['grand_total'], 'value' => $money->money($doc->grand_total ?? 0), 'emphasis' => true];

        if ($includeSettlement) {
            if (isset($doc->paid_total)) {
                $rows[] = ['label' => $labels['paid_total'], 'value' => $money->money($doc->paid_total)];
            }
            if (isset($doc->credited_total)) {
                $rows[] = ['label' => $labels['credited_total'], 'value' => $money->money($doc->credited_total)];
            }
            if (isset($doc->net_to_pay)) {
                $rows[] = ['label' => $labels['net_to_pay'], 'value' => $money->money($doc->net_to_pay), 'emphasis' => true];
            }
        }

        return $rows;
    }

    /**
     * Quantity-only lines (goods receipts, stock transfers): code, description, qty, uom and optional unit cost.
     *
     * @param  iterable<int, object>  $lines
     * @return list<array<string, string>>
     */
    public static function quantityLines(iterable $lines, DocumentMoney $money, bool $includeUnitCost = false): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $item = $line->item ?? null;
            $uom = $line->itemUom?->uom?->code
                ?? $line->itemUom?->uom?->name
                ?? null;
            $description = trim((string) ($line->description ?? ''));
            if ($description === '') {
                $description = (string) ($item?->name ?? '');
            }

            $row = [
                'code' => $money->dash($item?->item_code ?? null),
                'description' => $money->dash($description),
                'qty' => $money->quantity($line->quantity ?? null),
                'uom' => $money->dash($uom !== null ? (string) $uom : null),
            ];
            if ($includeUnitCost) {
                $row['unit_cost'] = isset($line->unit_cost) ? $money->money($line->unit_cost) : '—';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public static function quantityColumns(bool $includeUnitCost = false): array
    {
        $cols = ['code', 'description', 'qty', 'uom'];
        if ($includeUnitCost) {
            $cols[] = 'unit_cost';
        }

        return $cols;
    }

    /**
     * @return array{title: string, name: string, lines: list<string>}
     */
    public static function warehouseBlock(string $title, ?object $warehouse, DocumentMoney $money): array
    {
        return [
            'title' => $title,
            'name' => $money->dash($warehouse?->name !== null ? (string) $warehouse->name : null),
            'lines' => array_values(array_filter([
                ! empty($warehouse?->shortcut_name) ? $money->text((string) $warehouse->shortcut_name) : null,
            ])),
        ];
    }

    /**
     * @return array{label: string, value: string}
     */
    public static function metaRow(string $label, ?string $value, DocumentMoney $money): array
    {
        return ['label' => $label, 'value' => $money->dash($value)];
    }

    /**
     * Currency and (when stored) exchange-rate rows for the header meta block.
     *
     * @param  array<string, string>  $labels
     * @return list<array{label: string, value: string}>
     */
    public static function currencyMeta(?object $currency, mixed $rate, DocumentMoney $money, array $labels): array
    {
        if ($currency === null) {
            return [];
        }

        $rows = [self::metaRow($labels['currency'], (string) $currency->code, $money)];
        if ($rate !== null && is_numeric((string) $rate) && (float) $rate > 0) {
            $rows[] = self::metaRow($labels['exchange_rate'], self::trimDecimal($rate), $money);
        }

        return $rows;
    }

    public static function trimDecimal(mixed $value): string
    {
        $text = (string) $value;
        if (! str_contains($text, '.')) {
            return $text;
        }

        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /**
     * Allocation table rows for receipts, payments, and refunds.
     *
     * @param  iterable<int, object>  $allocations
     * @param  callable(object): ?string  $documentNumber
     * @return list<array{document_number: string, amount: string}>
     */
    public static function allocationRows(iterable $allocations, DocumentMoney $money, callable $documentNumber): array
    {
        $rows = [];
        foreach ($allocations as $allocation) {
            $rows[] = [
                'document_number' => $money->dash($documentNumber($allocation)),
                'amount' => $money->money($allocation->amount ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Payment lines of a sales invoice for printing: method and cash box, the amount in its own currency
     * (with its value in the invoice currency when they differ), and cash given / change / cheque / credit details.
     *
     * @param  iterable<int, object>  $payments  sales invoice payments with paymentMethod, cashBankAccount, currency, changeCurrency, customerReceipt.allocations loaded
     * @param  array<string, string>  $labels
     * @return list<array{method: string, amount: string, equivalent: ?string, details: list<string>}>
     */
    public static function paymentRows(iterable $payments, object $invoice, DocumentMoney $money, array $labels): array
    {
        $rows = [];
        $invoiceCode = (string) ($invoice->currency?->code ?? '');
        foreach ($payments as $payment) {
            $isCredit = ($payment->kind ?? 'payment') === 'credit';
            $code = (string) ($payment->currency?->code ?? $invoiceCode);
            $method = $isCredit
                ? $labels['customer_credit']
                : collect([$payment->paymentMethod?->name, $payment->cashBankAccount?->code])->filter()->implode(' · ');

            $equivalent = null;
            $receipt = $payment->customerReceipt ?? null;
            $applied = null;
            if ($receipt !== null) {
                $applied = collect($receipt->allocations ?? [])->firstWhere('sales_invoice_id', $invoice->id)?->applied_amount;
            }
            if ($code !== $invoiceCode && $applied !== null) {
                $equivalent = '≈ '.$money->money($applied).' '.$invoiceCode;
            }

            $details = [];
            if ($payment->tendered !== null && $payment->change_due !== null && (float) $payment->change_due > 0) {
                $details[] = $labels['cash_given'].': '.$money->money($payment->tendered).' '.$code;
                $changeCode = (string) ($payment->changeCurrency?->code ?? $code);
                $changeAmount = $payment->change_amount !== null && $payment->change_currency_id !== null ? $payment->change_amount : $payment->change_due;
                $details[] = $labels['change'].': '.$money->money($changeAmount).' '.$changeCode;
            }
            if (! empty($payment->cheque_number)) {
                $details[] = $labels['cheque'].' '.collect([
                    $payment->cheque_number,
                    $payment->cheque_bank,
                    $payment->cheque_due_date !== null ? $money->date($payment->cheque_due_date) : null,
                ])->filter()->implode(' · ');
            }
            if ($payment->reference !== null && $payment->reference !== '' && empty($payment->cheque_number)) {
                $details[] = $labels['reference'].': '.$payment->reference;
            }
            if ($receipt !== null && $applied !== null && isset($receipt->amount)) {
                $allocated = collect($receipt->allocations ?? [])->sum(fn ($a): float => (float) $a->amount);
                $kept = round((float) $receipt->amount - $allocated, 4);
                if ($kept > 0.00005) {
                    $details[] = $labels['kept_as_credit'].': '.$money->money($kept).' '.$code;
                }
            }

            $rows[] = [
                'method' => $money->dash($method),
                'amount' => $money->money($payment->amount).' '.$code,
                'equivalent' => $equivalent,
                'details' => array_map(fn (string $d): string => $money->text($d), $details),
            ];
        }

        return $rows;
    }

    /**
     * Amount row plus the part not allocated to any document (when there is one).
     *
     * @param  iterable<int, object>  $allocations
     * @param  array<string, string>  $labels
     * @return list<array{label: string, value: string, emphasis?: bool}>
     */
    public static function paymentTotals(mixed $amount, iterable $allocations, DocumentMoney $money, array $labels): array
    {
        $amountText = is_numeric((string) $amount) ? (string) $amount : '0';
        $allocated = '0';
        foreach ($allocations as $allocation) {
            $value = (string) ($allocation->amount ?? '0');
            $allocated = bcadd($allocated, is_numeric($value) ? $value : '0', 4);
        }

        $rows = [['label' => $labels['amount'], 'value' => $money->money($amountText), 'emphasis' => true]];
        $open = bcsub($amountText, $allocated, 4);
        if (bccomp($open, '0', 4) > 0) {
            $rows[] = ['label' => $labels['open_amount'], 'value' => $money->money($open)];
        }

        return $rows;
    }

    /**
     * @return array{title: string, name: string, lines: list<string>}
     */
    public static function partyBlock(string $title, ?object $party, DocumentMoney $money, string $codeField = 'customer_code'): array
    {
        $name = $party?->company_name ?: ($party?->name ?? '—');
        $lines = [];
        $code = $party?->{$codeField} ?? null;
        if ($code) {
            $lines[] = $money->text((string) $code);
        }
        if (! empty($party?->email)) {
            $lines[] = $money->text((string) $party->email);
        }
        if (! empty($party?->phone)) {
            $lines[] = $money->text((string) $party->phone);
        }
        if (! empty($party?->tax_number)) {
            $lines[] = $money->text((string) $party->tax_number);
        }

        return [
            'title' => $title,
            'name' => $money->dash(is_string($name) ? $name : null),
            'lines' => $lines,
        ];
    }
}
