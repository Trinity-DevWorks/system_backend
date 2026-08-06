<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\Enums;

/**
 * Display grouping for numbers.
 * Naming: {thousands}_{decimal}
 */
enum NumberFormat: string
{
    /** 1,234.56 */
    case CommaDot = 'comma_dot';
    /** 1.234,56 */
    case DotComma = 'dot_comma';
    /** 1 234.56 */
    case SpaceDot = 'space_dot';
    /** 1 234,56 */
    case SpaceComma = 'space_comma';
}
