<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\Support;

use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\SalesQuotation\Enums\SalesQuotationStatus;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;

final class SalesQuotationRules
{
    public static function assertDraft(SalesQuotation $quotation): void
    {
        if ($quotation->status !== SalesQuotationStatus::Draft) {
            abort(422, 'Only a draft quotation can be modified.', ['X-Error-Code' => 'SALES_QUOTATION_NOT_DRAFT']);
        }
    }

    public static function assertCustomer(string $customerId): Customer
    {
        $customer = Customer::query()->findOrFail($customerId);

        if ($customer->status === CustomerStatus::Blacklisted) {
            abort(422, 'Customer is blacklisted.', ['X-Error-Code' => 'SALES_QUOTATION_CUSTOMER_BLACKLISTED']);
        }

        if ($customer->status !== CustomerStatus::Active && ! $customer->is_system) {
            abort(422, 'Customer must be active.', ['X-Error-Code' => 'SALES_QUOTATION_CUSTOMER_INACTIVE']);
        }

        return $customer;
    }
}
