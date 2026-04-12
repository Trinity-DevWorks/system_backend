<?php

declare(strict_types=1);

namespace App\Modules\Customer\Enums;

enum CustomerType: string
{
    case Individual = 'individual';
    case Business = 'business';
}
