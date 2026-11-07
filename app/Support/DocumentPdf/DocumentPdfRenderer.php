<?php

declare(strict_types=1);

namespace App\Support\DocumentPdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

final class DocumentPdfRenderer
{
    public function __construct(
        private readonly DocumentLetterhead $letterhead,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data, DocumentPdfOptions $options): DomPdf
    {
        $money = DocumentMoney::forOptions($options);
        $payload = array_merge([
            'letterhead' => $this->letterhead->resolve($options->isArabic(), $options),
            'locale' => $options->locale,
            'rtl' => $options->isArabic(),
            'generatedAt' => $money->dateTime(now()) ?: now()->format('Y-m-d H:i'),
            'showSignatures' => true,
            'statusWatermark' => '',
            'meta' => [],
            'parties' => [],
            'lineColumns' => [],
            'lines' => [],
            'totals' => [],
            'allocations' => null,
            'payments' => null,
            'notes' => null,
            'labels' => $this->labels($options),
        ], $data);

        // Tenant print settings: signatures can only be turned off, never forced on.
        $payload['showSignatures'] = (bool) $payload['showSignatures'] && $options->showSignatures;
        $payload['footerText'] = $options->footerText !== null ? $money->text($options->footerText) : null;
        $payload['termsText'] = $options->termsText !== null ? $money->text($options->termsText) : null;

        if ($options->isReceipt() && $view === 'document-pdf.document') {
            $pdf = Pdf::loadView('document-pdf.receipt', $payload)->setPaper([0, 0, self::RECEIPT_WIDTH, $this->receiptHeight($payload)]);
            $pdf->render();

            return $pdf;
        }

        $pdf = Pdf::loadView($view, $payload)->setPaper($options->paper);
        $this->stampPageNumbers($pdf, $payload['labels']);

        return $pdf;
    }

    /** 80mm roll in points. */
    public const RECEIPT_WIDTH = 226.77;

    /**
     * Roll paper has no fixed height: estimate it from the content so the receipt prints on one page.
     *
     * @param  array<string, mixed>  $payload
     */
    private function receiptHeight(array $payload): float
    {
        $count = static fn (mixed $v): int => is_array($v) ? count($v) : 0;
        $textLines = static fn (?string $v): int => $v === null || $v === '' ? 0 : (int) ceil(mb_strlen($v) / 38) + substr_count($v, "\n");

        $height = 200
            + 30 * $count($payload['lines'] ?? [])
            + 16 * $count($payload['allocations'] ?? [])
            + 30 * $count($payload['payments'] ?? [])
            + 14 * $count($payload['totals'] ?? [])
            + 13 * $count($payload['meta'] ?? [])
            + 40 * $count($payload['parties'] ?? [])
            + 11 * ($textLines(is_string($payload['notes'] ?? null) ? $payload['notes'] : null)
                + $textLines(is_string($payload['termsText'] ?? null) ? $payload['termsText'] : null)
                + $textLines(is_string($payload['footerText'] ?? null) ? $payload['footerText'] : null));
        if (! empty($payload['letterhead']['logo_data_uri'])) {
            $height += 50;
        }

        return (float) max(300, $height);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function stampPageNumbers(DomPdf $pdf, array $labels): void
    {
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans');
        if ($font === null) {
            return;
        }

        $text = ($labels['page'] ?? 'Page').' {PAGE_NUM} '.($labels['of'] ?? 'of').' {PAGE_COUNT}';
        // Bottom-right margin area, measured from the actual paper size.
        $canvas->page_text($canvas->get_width() - 175, $canvas->get_height() - 22, $text, $font, 8, [0.53, 0.53, 0.53]);
    }

    /**
     * @return array<string, string>
     */
    public function labelsFor(DocumentPdfOptions $options): array
    {
        return $this->labels($options);
    }

    /**
     * @return array<string, string>
     */
    private function labels(DocumentPdfOptions $options): array
    {
        $money = DocumentMoney::forOptions($options);

        $en = [
            'bill_to' => 'Bill to',
            'ship_to' => 'Ship to',
            'pay_to' => 'Pay to',
            'supplier' => 'Supplier',
            'customer' => 'Customer',
            'deliver_to' => 'Deliver to',
            'from_warehouse' => 'From warehouse',
            'to_warehouse' => 'To warehouse',
            'code' => 'Code',
            'description' => 'Description',
            'qty' => 'Qty',
            'uom' => 'UOM',
            'unit_price' => 'Unit price',
            'unit_cost' => 'Unit cost',
            'discount' => 'Discount',
            'tax' => 'Tax',
            'line_total' => 'Line total',
            'account' => 'Account',
            'debit' => 'Debit',
            'credit' => 'Credit',
            'document' => 'Document',
            'allocated' => 'Allocated',
            'subtotal' => 'Subtotal',
            'discount_total' => 'Discount',
            'tax_total' => 'Tax',
            'adjustment' => 'Adjustment',
            'grand_total' => 'Grand total',
            'paid_total' => 'Paid',
            'credited_total' => 'Credited',
            'net_to_pay' => 'Net to pay',
            'amount' => 'Amount',
            'open_amount' => 'Open amount',
            'notes' => 'Notes',
            'prepared_by' => 'Prepared by',
            'approved_by' => 'Approved by',
            'page' => 'Page',
            'of' => 'of',
            'generated' => 'Generated',
            'date' => 'Date',
            'due_date' => 'Due date',
            'validity' => 'Valid until',
            'requested_date' => 'Requested date',
            'expected_date' => 'Expected date',
            'currency' => 'Currency',
            'exchange_rate' => 'Exchange rate',
            'payment_method' => 'Payment method',
            'reference' => 'Reference',
            'payments' => 'Payments',
            'cash_given' => 'Cash given',
            'change' => 'Change',
            'cheque' => 'Cheque',
            'customer_credit' => 'Customer credit',
            'kept_as_credit' => 'Kept as credit',
            'source' => 'Source',
            'warehouse' => 'Warehouse',
            'po_number' => 'Purchase order',
            'order_number' => 'Sales order',
            'invoice_number' => 'Invoice',
            'invoiced_qty' => 'Invoiced',
            'remaining_qty' => 'Remaining',
            'debited_total' => 'Debited',
            'applied_total' => 'Applied',
            'refunded_total' => 'Refunded',
            'open_credit' => 'Open credit',
            'grn_number' => 'Goods receipt',
            'quotation_number' => 'Quotation',
            'reason' => 'Reason',
            'total_debit' => 'Total debit',
            'total_credit' => 'Total credit',
        ];

        $ar = [
            'bill_to' => 'فاتورة إلى',
            'ship_to' => 'شحن إلى',
            'pay_to' => 'الدفع إلى',
            'supplier' => 'المورد',
            'customer' => 'العميل',
            'deliver_to' => 'التسليم إلى',
            'from_warehouse' => 'من المستودع',
            'to_warehouse' => 'إلى المستودع',
            'code' => 'الرمز',
            'description' => 'الوصف',
            'qty' => 'الكمية',
            'uom' => 'الوحدة',
            'unit_price' => 'سعر الوحدة',
            'unit_cost' => 'تكلفة الوحدة',
            'discount' => 'الخصم',
            'tax' => 'الضريبة',
            'line_total' => 'الإجمالي',
            'account' => 'الحساب',
            'debit' => 'مدين',
            'credit' => 'دائن',
            'document' => 'المستند',
            'allocated' => 'المخصص',
            'subtotal' => 'المجموع الفرعي',
            'discount_total' => 'الخصم',
            'tax_total' => 'الضريبة',
            'adjustment' => 'التسوية',
            'grand_total' => 'الإجمالي',
            'paid_total' => 'المدفوع',
            'credited_total' => 'المدفوع دائناً',
            'net_to_pay' => 'صافي المستحق',
            'amount' => 'المبلغ',
            'open_amount' => 'المبلغ المفتوح',
            'notes' => 'ملاحظات',
            'prepared_by' => 'أعده',
            'approved_by' => 'اعتمده',
            'page' => 'صفحة',
            'of' => 'من',
            'generated' => 'تاريخ الإنشاء',
            'date' => 'التاريخ',
            'due_date' => 'تاريخ الاستحقاق',
            'validity' => 'صالح حتى',
            'requested_date' => 'التاريخ المطلوب',
            'expected_date' => 'التاريخ المتوقع',
            'currency' => 'العملة',
            'exchange_rate' => 'سعر الصرف',
            'payment_method' => 'طريقة الدفع',
            'reference' => 'المرجع',
            'payments' => 'الدفعات',
            'cash_given' => 'المبلغ المستلم',
            'change' => 'الباقي',
            'cheque' => 'شيك',
            'customer_credit' => 'رصيد العميل',
            'kept_as_credit' => 'أُبقي كرصيد',
            'source' => 'المصدر',
            'warehouse' => 'المستودع',
            'po_number' => 'أمر الشراء',
            'order_number' => 'أمر البيع',
            'invoice_number' => 'الفاتورة',
            'invoiced_qty' => 'المفوتر',
            'remaining_qty' => 'المتبقي',
            'debited_total' => 'المدين',
            'applied_total' => 'المطبق',
            'refunded_total' => 'المسترد',
            'open_credit' => 'الرصيد المفتوح',
            'grn_number' => 'إيصال الاستلام',
            'quotation_number' => 'عرض السعر',
            'reason' => 'السبب',
            'total_debit' => 'إجمالي المدين',
            'total_credit' => 'إجمالي الدائن',
        ];

        $source = $options->isArabic() ? $ar : $en;
        $out = [];
        foreach ($source as $key => $label) {
            $out[$key] = $money->text($label);
        }

        return $out;
    }
}
