<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Enums;

enum RecurringRunStatus: string
{
    case Generated = 'generated';
    case Posted = 'posted';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
