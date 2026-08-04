<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\Enums;

enum DateFormat: string
{
    case YmdDash = 'Y-m-d';
    case DmYSlash = 'd/m/Y';
    case MdYSlash = 'm/d/Y';
    case DmYDash = 'd-m-Y';
    case DmYDot = 'd.m.Y';
}
