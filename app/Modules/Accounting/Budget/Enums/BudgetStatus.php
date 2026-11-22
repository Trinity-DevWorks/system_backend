<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Enums;

enum BudgetStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Archived = 'archived';
}
