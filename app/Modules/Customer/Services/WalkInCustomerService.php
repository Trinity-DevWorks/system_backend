<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Enums\CustomerType;
use App\Modules\Customer\Models\Customer;

final class WalkInCustomerService
{
    public const CODE = 'WALK-IN';

    public const NAME = 'Walk-in';

    /**
     * Create or repair the tenant walk-in / cash customer.
     */
    public function ensure(): Customer
    {
        $customer = Customer::query()->firstOrCreate(
            ['customer_code' => self::CODE],
            [
                'name' => self::NAME,
                'type' => CustomerType::Individual,
                'status' => CustomerStatus::Active,
                'is_system' => true,
                'notes' => 'System cash customer for counter and POS sales.',
            ]
        );

        $dirty = [];
        if (! $customer->is_system) {
            $dirty['is_system'] = true;
        }
        if ($customer->status !== CustomerStatus::Active) {
            $dirty['status'] = CustomerStatus::Active;
            $dirty['blacklist_reason'] = null;
        }
        if ($dirty !== []) {
            $customer->update($dirty);
        }

        return $customer->refresh();
    }

    public function current(): ?Customer
    {
        return Customer::query()
            ->where('customer_code', self::CODE)
            ->where('is_system', true)
            ->first();
    }
}
