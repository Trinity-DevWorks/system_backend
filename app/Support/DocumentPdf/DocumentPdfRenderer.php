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
            'letterhead' => $this->letterhead->resolve($options->isArabic()),
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
            'notes' => null,
            'labels' => $this->labels($options),
        ], $data);

        $pdf = Pdf::loadView($view, $payload)->setPaper($options->paper);
        $this->stampPageNumbers($pdf, $payload['labels']);

        return $pdf;
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
        // A4 portrait: ~595 x 842 pt; place in the bottom-right margin area.
        $canvas->page_text(420, 820, $text, $font, 8, [0.53, 0.53, 0.53]);
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
