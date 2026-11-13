<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Services;

use App\Modules\Accounting\FixedAsset\Enums\DepreciationMethod;

/**
 * The numbers the depreciation calculator needs. Kept separate from the Eloquent model so the math can be tested on its own.
 */
readonly class DepreciationProfile
{
    public function __construct(
        public string $cost,
        public string $salvageValue,
        public string $accumulatedDepreciation,
        public DepreciationMethod $method,
        public int $usefulLifeMonths,
        public ?string $decliningRate,
        public bool $prorateFirstMonth,
        public string $inServiceDate,
        public ?string $depreciatedThrough,
        public int $scale = 2,
    ) {}

    public function advance(string $accumulated, string $through): self
    {
        return new self(
            cost: $this->cost,
            salvageValue: $this->salvageValue,
            accumulatedDepreciation: $accumulated,
            method: $this->method,
            usefulLifeMonths: $this->usefulLifeMonths,
            decliningRate: $this->decliningRate,
            prorateFirstMonth: $this->prorateFirstMonth,
            inServiceDate: $this->inServiceDate,
            depreciatedThrough: $through,
            scale: $this->scale,
        );
    }
}
