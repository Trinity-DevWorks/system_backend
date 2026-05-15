<?php

declare(strict_types=1);

namespace App\Modules\Customer\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Blacklisted = 'blacklisted';
}
