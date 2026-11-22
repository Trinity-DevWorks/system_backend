<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Support;

use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Services\PurchaseDebitNotePdfService;
use App\Modules\Inventory\Purchasing\Services\PurchaseOrderPdfService;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPayment;
use App\Modules\Inventory\Purchasing\SupplierPayment\Services\SupplierPaymentPdfService;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerReceipt\Services\CustomerReceiptPdfService;
use App\Modules\Sales\CustomerRefund\Models\CustomerRefund;
use App\Modules\Sales\CustomerRefund\Services\CustomerRefundPdfService;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNote;
use App\Modules\Sales\DeliveryNote\Services\DeliveryNotePdfService;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesCreditNote\Services\SalesCreditNotePdfService;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Services\SalesInvoicePdfService;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Sales\SalesOrder\Services\SalesOrderPdfService;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;
use App\Modules\Sales\SalesQuotation\Services\SalesQuotationPdfService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

/**
 * Every document that can be emailed: which model, which PDF, who receives it,
 * which permission allows it and in which statuses it may go out.
 *
 * Documents need the same permission as their PDF (`print`); statements the
 * same as their PDF export (`view` on the customer / supplier).
 *
 * @phpstan-type Definition array{
 *     model: class-string<Model>,
 *     pdf: class-string|null,
 *     resource: string,
 *     action: string,
 *     party: 'customer'|'supplier',
 *     number: string|null,
 *     date: string|null,
 *     total: string|null,
 *     due: string|null,
 *     statuses: list<string>|null,
 * }
 */
final class DocumentEmailRegistry
{
    public const CUSTOMER_STATEMENT = 'customer_statement';

    public const SUPPLIER_STATEMENT = 'supplier_statement';

    /** @var array<string, Definition> */
    private const TYPES = [
        'sales_quotation' => [
            'model' => SalesQuotation::class,
            'pdf' => SalesQuotationPdfService::class,
            'resource' => 'sales_quotations',
            'action' => 'print',
            'party' => 'customer',
            'number' => 'quotation_number',
            'date' => 'quotation_date',
            'total' => 'grand_total',
            'due' => null,
            'statuses' => ['draft', 'sent', 'accepted', 'converted'],
        ],
        'sales_order' => [
            'model' => SalesOrder::class,
            'pdf' => SalesOrderPdfService::class,
            'resource' => 'sales_orders',
            'action' => 'print',
            'party' => 'customer',
            'number' => 'order_number',
            'date' => 'order_date',
            'total' => 'grand_total',
            'due' => null,
            'statuses' => ['confirmed', 'partially_invoiced', 'invoiced'],
        ],
        'sales_invoice' => [
            'model' => SalesInvoice::class,
            'pdf' => SalesInvoicePdfService::class,
            'resource' => 'sales_invoices',
            'action' => 'print',
            'party' => 'customer',
            'number' => 'invoice_number',
            'date' => 'invoice_date',
            'total' => 'grand_total',
            'due' => 'due_on',
            'statuses' => ['posted', 'reversed'],
        ],
        'delivery_note' => [
            'model' => DeliveryNote::class,
            'pdf' => DeliveryNotePdfService::class,
            'resource' => 'delivery_notes',
            'action' => 'print',
            'party' => 'customer',
            'number' => 'delivery_number',
            'date' => 'delivery_date',
            'total' => null,
            'due' => null,
            'statuses' => ['posted', 'reversed'],
        ],
        'sales_credit_note' => [
            'model' => SalesCreditNote::class,
            'pdf' => SalesCreditNotePdfService::class,
            'resource' => 'sales_credit_notes',
            'action' => 'print',
            'party' => 'customer',
            'number' => 'credit_note_number',
            'date' => 'credit_date',
            'total' => 'grand_total',
            'due' => null,
            'statuses' => ['posted', 'reversed'],
        ],
        'customer_receipt' => [
            'model' => CustomerReceipt::class,
            'pdf' => CustomerReceiptPdfService::class,
            'resource' => 'customer_receipts',
            'action' => 'print',
            'party' => 'customer',
            'number' => 'receipt_number',
            'date' => 'payment_date',
            'total' => 'amount',
            'due' => null,
            'statuses' => ['posted', 'reversed'],
        ],
        'customer_refund' => [
            'model' => CustomerRefund::class,
            'pdf' => CustomerRefundPdfService::class,
            'resource' => 'customer_refunds',
            'action' => 'print',
            'party' => 'customer',
            'number' => 'refund_number',
            'date' => 'refund_date',
            'total' => 'amount',
            'due' => null,
            'statuses' => ['posted', 'reversed'],
        ],
        'purchase_order' => [
            'model' => PurchaseOrder::class,
            'pdf' => PurchaseOrderPdfService::class,
            'resource' => 'stock',
            'action' => 'print',
            'party' => 'supplier',
            'number' => 'po_number',
            'date' => 'order_date',
            'total' => 'grand_total',
            'due' => 'expected_date',
            'statuses' => ['confirmed', 'sent', 'closed'],
        ],
        'purchase_debit_note' => [
            'model' => PurchaseDebitNote::class,
            'pdf' => PurchaseDebitNotePdfService::class,
            'resource' => 'purchase_debit_notes',
            'action' => 'print',
            'party' => 'supplier',
            'number' => 'debit_note_number',
            'date' => 'debit_date',
            'total' => 'grand_total',
            'due' => null,
            'statuses' => ['posted', 'reversed'],
        ],
        'supplier_payment' => [
            'model' => SupplierPayment::class,
            'pdf' => SupplierPaymentPdfService::class,
            'resource' => 'supplier_payments',
            'action' => 'print',
            'party' => 'supplier',
            'number' => 'payment_number',
            'date' => 'payment_date',
            'total' => 'amount',
            'due' => null,
            'statuses' => ['posted', 'reversed'],
        ],
        self::CUSTOMER_STATEMENT => [
            'model' => Customer::class,
            'pdf' => null,
            'resource' => 'customers',
            'action' => 'view',
            'party' => 'customer',
            'number' => null,
            'date' => null,
            'total' => null,
            'due' => null,
            'statuses' => null,
        ],
        self::SUPPLIER_STATEMENT => [
            'model' => Supplier::class,
            'pdf' => null,
            'resource' => 'suppliers',
            'action' => 'view',
            'party' => 'supplier',
            'number' => null,
            'date' => null,
            'total' => null,
            'due' => null,
            'statuses' => null,
        ],
    ];

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * @return Definition
     */
    public static function definition(string $type): array
    {
        if (! isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("Unknown document email type [{$type}].");
        }

        return self::TYPES[$type];
    }

    public static function isStatement(string $type): bool
    {
        return $type === self::CUSTOMER_STATEMENT || $type === self::SUPPLIER_STATEMENT;
    }
}
