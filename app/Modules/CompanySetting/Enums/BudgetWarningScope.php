<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Enums;

enum BudgetWarningScope: string
{
    case Month = 'month';
    case Ytd = 'ytd';
}
