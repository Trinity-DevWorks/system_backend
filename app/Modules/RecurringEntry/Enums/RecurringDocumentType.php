<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Enums;

enum RecurringDocumentType: string
{
    case JournalEntry = 'journal_entry';
    case ExpenseVoucher = 'expense_voucher';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
