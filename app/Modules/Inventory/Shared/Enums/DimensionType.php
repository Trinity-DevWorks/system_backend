<?php

namespace App\Modules\Inventory\Shared\Enums;

enum DimensionType: string
{
    case Count = 'count';
    case Weight = 'weight';
    case Length = 'length';
    case Volume = 'volume';
    case Other = 'other';
}
