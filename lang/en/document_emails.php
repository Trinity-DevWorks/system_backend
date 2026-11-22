<?php

declare(strict_types=1);

/*
| Default email texts per document type. Companies can override them in
| Settings → Email. Placeholders: {party} {number} {date} {total} {due_date}
| {company} {period}.
*/

return [
    'templates' => [
        'sales_quotation' => [
            'subject' => 'Quotation {number} from {company}',
            'body' => "Dear {party},\n\nPlease find attached our quotation {number} dated {date} for {total}.\n\nWe look forward to hearing from you.\n\nBest regards,\n{company}",
        ],
        'sales_order' => [
            'subject' => 'Order confirmation {number} from {company}',
            'body' => "Dear {party},\n\nThank you for your order. Please find attached the order confirmation {number} dated {date} for {total}.\n\nBest regards,\n{company}",
        ],
        'sales_invoice' => [
            'subject' => 'Invoice {number} from {company}',
            'body' => "Dear {party},\n\nPlease find attached invoice {number} dated {date} for {total}, due on {due_date}.\n\nThank you for your business.\n\nBest regards,\n{company}",
        ],
        'delivery_note' => [
            'subject' => 'Delivery note {number} from {company}',
            'body' => "Dear {party},\n\nPlease find attached delivery note {number} dated {date}.\n\nBest regards,\n{company}",
        ],
        'sales_credit_note' => [
            'subject' => 'Credit note {number} from {company}',
            'body' => "Dear {party},\n\nPlease find attached credit note {number} dated {date} for {total}.\n\nBest regards,\n{company}",
        ],
        'customer_receipt' => [
            'subject' => 'Payment receipt {number} from {company}',
            'body' => "Dear {party},\n\nThank you for your payment of {total}. Please find attached receipt {number} dated {date}.\n\nBest regards,\n{company}",
        ],
        'customer_refund' => [
            'subject' => 'Refund {number} from {company}',
            'body' => "Dear {party},\n\nWe have refunded {total} to you. Please find attached refund {number} dated {date}.\n\nBest regards,\n{company}",
        ],
        'purchase_order' => [
            'subject' => 'Purchase order {number} from {company}',
            'body' => "Dear {party},\n\nPlease find attached our purchase order {number} dated {date} for {total}. Expected delivery: {due_date}.\n\nPlease confirm receipt of this order.\n\nBest regards,\n{company}",
        ],
        'purchase_debit_note' => [
            'subject' => 'Debit note {number} from {company}',
            'body' => "Dear {party},\n\nPlease find attached debit note {number} dated {date} for {total}.\n\nBest regards,\n{company}",
        ],
        'supplier_payment' => [
            'subject' => 'Payment advice {number} from {company}',
            'body' => "Dear {party},\n\nWe have sent you a payment of {total}. Please find attached payment advice {number} dated {date}.\n\nBest regards,\n{company}",
        ],
        'customer_statement' => [
            'subject' => 'Account statement from {company}',
            'body' => "Dear {party},\n\nPlease find attached your account statement for {period}.\n\nIf you have any questions, just reply to this email.\n\nBest regards,\n{company}",
        ],
        'supplier_statement' => [
            'subject' => 'Account statement from {company}',
            'body' => "Dear {party},\n\nPlease find attached our statement of your account for {period}. Please let us know if anything does not match your records.\n\nBest regards,\n{company}",
        ],
    ],

    'period_all' => 'all dates',
    'period_from' => 'from :from',
    'period_until' => 'until :to',
    'period_range' => ':from to :to',

    'test_subject' => 'Test email from :company',
    'test_body' => "This is a test email from your ERP.\n\nIf you received it, :company can now send documents by email.",
    'sent_from' => 'Sent from :company',
];
