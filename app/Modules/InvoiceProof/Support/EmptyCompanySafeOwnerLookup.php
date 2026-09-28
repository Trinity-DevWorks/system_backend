<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\InvoiceProof\Contracts\CompanySafeOwnerLookup;
use App\Modules\InvoiceProof\DTOs\WalletInspectionData;

final class EmptyCompanySafeOwnerLookup implements CompanySafeOwnerLookup
{
    public function ownersOf(string $safeAddress): array
    {
        return [];
    }

    public function inspect(string $address): ?WalletInspectionData
    {
        return null;
    }
}
