<?php

declare(strict_types=1);

/*
| نصوص البريد الافتراضية لكل نوع مستند. يمكن للشركة تعديلها من الإعدادات ← البريد الإلكتروني.
| المتغيرات: {party} {number} {date} {total} {due_date} {company} {period}
*/

return [
    'templates' => [
        'sales_quotation' => [
            'subject' => 'عرض سعر {number} من {company}',
            'body' => "السادة {party} المحترمين،\n\nتجدون مرفقاً عرض السعر رقم {number} بتاريخ {date} بقيمة {total}.\n\nبانتظار ردكم.\n\nمع خالص التحية،\n{company}",
        ],
        'sales_order' => [
            'subject' => 'تأكيد الطلب {number} من {company}',
            'body' => "السادة {party} المحترمين،\n\nشكراً لطلبكم. تجدون مرفقاً تأكيد الطلب رقم {number} بتاريخ {date} بقيمة {total}.\n\nمع خالص التحية،\n{company}",
        ],
        'sales_invoice' => [
            'subject' => 'فاتورة {number} من {company}',
            'body' => "السادة {party} المحترمين،\n\nتجدون مرفقاً الفاتورة رقم {number} بتاريخ {date} بقيمة {total}، تاريخ الاستحقاق {due_date}.\n\nشكراً لتعاملكم معنا.\n\nمع خالص التحية،\n{company}",
        ],
        'delivery_note' => [
            'subject' => 'إشعار تسليم {number} من {company}',
            'body' => "السادة {party} المحترمين،\n\nتجدون مرفقاً إشعار التسليم رقم {number} بتاريخ {date}.\n\nمع خالص التحية،\n{company}",
        ],
        'sales_credit_note' => [
            'subject' => 'إشعار دائن {number} من {company}',
            'body' => "السادة {party} المحترمين،\n\nتجدون مرفقاً الإشعار الدائن رقم {number} بتاريخ {date} بقيمة {total}.\n\nمع خالص التحية،\n{company}",
        ],
        'customer_receipt' => [
            'subject' => 'سند قبض {number} من {company}',
            'body' => "السادة {party} المحترمين،\n\nشكراً لدفعتكم بقيمة {total}. تجدون مرفقاً سند القبض رقم {number} بتاريخ {date}.\n\nمع خالص التحية،\n{company}",
        ],
        'customer_refund' => [
            'subject' => 'استرداد {number} من {company}',
            'body' => "السادة {party} المحترمين،\n\nتم رد مبلغ {total} لكم. تجدون مرفقاً سند الاسترداد رقم {number} بتاريخ {date}.\n\nمع خالص التحية،\n{company}",
        ],
        'purchase_order' => [
            'subject' => 'أمر شراء {number} من {company}',
            'body' => "السادة {party} المحترمين،\n\nتجدون مرفقاً أمر الشراء رقم {number} بتاريخ {date} بقيمة {total}. تاريخ التسليم المتوقع: {due_date}.\n\nيرجى تأكيد استلام الطلب.\n\nمع خالص التحية،\n{company}",
        ],
        'purchase_debit_note' => [
            'subject' => 'إشعار مدين {number} من {company}',
            'body' => "السادة {party} المحترمين،\n\nتجدون مرفقاً الإشعار المدين رقم {number} بتاريخ {date} بقيمة {total}.\n\nمع خالص التحية،\n{company}",
        ],
        'supplier_payment' => [
            'subject' => 'إشعار دفع {number} من {company}',
            'body' => "السادة {party} المحترمين،\n\nتم تحويل دفعة بقيمة {total} لكم. تجدون مرفقاً إشعار الدفع رقم {number} بتاريخ {date}.\n\nمع خالص التحية،\n{company}",
        ],
        'customer_statement' => [
            'subject' => 'كشف حساب من {company}',
            'body' => "السادة {party} المحترمين،\n\nتجدون مرفقاً كشف حسابكم عن الفترة {period}.\n\nلأي استفسار يمكنكم الرد على هذا البريد.\n\nمع خالص التحية،\n{company}",
        ],
        'supplier_statement' => [
            'subject' => 'كشف حساب من {company}',
            'body' => "السادة {party} المحترمين،\n\nتجدون مرفقاً كشف حسابكم لدينا عن الفترة {period}. يرجى إعلامنا في حال وجود أي اختلاف مع سجلاتكم.\n\nمع خالص التحية،\n{company}",
        ],
    ],

    'period_all' => 'كل التواريخ',
    'period_from' => 'من :from',
    'period_until' => 'حتى :to',
    'period_range' => 'من :from إلى :to',

    'test_subject' => 'رسالة تجريبية من :company',
    'test_body' => "هذه رسالة تجريبية من نظام ERP.\n\nإذا وصلتك، فإن :company أصبحت قادرة على إرسال المستندات بالبريد الإلكتروني.",
    'sent_from' => 'مرسلة من :company',
];
