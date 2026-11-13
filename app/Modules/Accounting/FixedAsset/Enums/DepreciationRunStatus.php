<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Enums;

enum DepreciationRunStatus: string
{
    case Posted = 'posted';
    case Undone = 'undone';
}
