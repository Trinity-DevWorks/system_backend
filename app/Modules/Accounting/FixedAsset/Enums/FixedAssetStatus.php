<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Enums;

enum FixedAssetStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case FullyDepreciated = 'fully_depreciated';
    case Disposed = 'disposed';
}
