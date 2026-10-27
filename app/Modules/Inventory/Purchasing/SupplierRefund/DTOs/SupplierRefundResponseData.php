<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierRefund\DTOs;

use App\Models\User;
use App\Modules\Branch\Support\DocumentBranchPayload;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Purchasing\SupplierRefund\Enums\SupplierRefundStatus;
use App\Modules\Inventory\Purchasing\SupplierRefund\Models\SupplierRefund;
use App\Modules\Inventory\Purchasing\SupplierRefund\Models\SupplierRefundAllocation;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use Illuminate\Support\Collection;

readonly class SupplierRefundResponseData
{
    /**
     * @return array<string, mixed>
     */
    public static function fromModel(SupplierRefund $refund, bool $includeAllocations = true): array
    {
        $refund->loadMissing([
            'supplier:id,supplier_code,name,phone,is_active',
            'currency:id,code,name,symbol',
            'paymentMethod:id,name,code,type,requires_reference,is_active',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $refund->id,
            ...DocumentBranchPayload::of($refund),
            'refund_number' => $refund->refund_number,
            'supplier_id' => $refund->supplier_id,
            'currency_id' => $refund->currency_id,
            'exchange_rate' => (string) $refund->exchange_rate,
            'payment_method_id' => $refund->payment_method_id,
            'refund_date' => $refund->refund_date?->toDateString(),
            'payment_date' => $refund->refund_date?->toDateString(),
            'amount' => (string) $refund->amount,
            'reference' => $refund->reference,
            'notes' => $refund->notes,
            'status' => $refund->status instanceof SupplierRefundStatus ? $refund->status->value : (string) $refund->status,
            'supplier' => $refund->supplier ? [
                'id' => $refund->supplier->id,
                'name' => $refund->supplier->name,
                'supplier_code' => $refund->supplier->supplier_code,
            ] : null,
            'currency' => self::currencyBrief($refund->currency),
            'payment_method' => self::methodBrief($refund->paymentMethod),
            'created_by' => self::userBrief($refund->createdByUser),
            'posted_by' => self::userBrief($refund->postedByUser),
            'posted_at' => $refund->posted_at?->toIso8601String(),
            'allocations_count' => $refund->allocations_count ?? null,
            'created_at' => (string) $refund->created_at,
            'updated_at' => (string) $refund->updated_at,
        ];

        if ($includeAllocations) {
            $refund->loadMissing('allocations.purchaseDebitNote.currency');
            $payload['allocations'] = self::allocationsToArray($refund->allocations);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, SupplierRefundAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    public static function allocationsToArray(Collection $allocations): array
    {
        return $allocations->map(function (SupplierRefundAllocation $allocation): array {
            $note = $allocation->purchaseDebitNote;

            return [
                'id' => $allocation->id,
                'purchase_debit_note_id' => $allocation->purchase_debit_note_id,
                'amount' => (string) $allocation->amount,
                'applied_amount' => (string) $allocation->applied_amount,
                'applied_exchange_rate' => (string) $allocation->applied_exchange_rate,
                'exchange_difference' => (string) $allocation->exchange_difference,
                'currency_id' => $note?->currency_id,
                'currency' => self::currencyBrief($note?->currency),
                'invoice_number' => $note?->debit_note_number,
                'invoice_date' => $note?->debit_date?->toDateString(),
                'grand_total' => $note ? (string) $note->grand_total : null,
                'net_to_pay' => $note ? (string) $note->open_credit : null,
            ];
        })->values()->all();
    }

    /**
     * @return array{id:int,code:?string,name:?string,symbol:?string}|null
     */
    private static function currencyBrief(?Currency $currency): ?array
    {
        if (! $currency) {
            return null;
        }

        return ['id' => $currency->id, 'code' => $currency->code, 'name' => $currency->name, 'symbol' => $currency->symbol];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function methodBrief(?PaymentMethod $method): ?array
    {
        if (! $method) {
            return null;
        }

        return [
            'id' => $method->id,
            'name' => $method->name,
            'code' => $method->code,
            'type' => $method->type instanceof \BackedEnum ? $method->type->value : (string) $method->type,
            'requires_reference' => (bool) $method->requires_reference,
            'is_active' => (bool) $method->is_active,
        ];
    }

    /**
     * @return array{id:string,name:?string,email:?string}|null
     */
    private static function userBrief(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }
}
