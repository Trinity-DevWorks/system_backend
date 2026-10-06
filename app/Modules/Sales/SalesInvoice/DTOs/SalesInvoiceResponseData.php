<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\DTOs;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Warehouse\Models\Warehouse;
use JsonException;

readonly class SalesInvoiceResponseData
{
    /**
     * @param  array{kind: string, checked_at: string|null}|null  $chainIssue  from InvoiceChainIssueLookup
     * @param  array{status: string, financed: bool, checked_at: string|null}|null  $chainStatus  from InvoiceChainStatusLookup
     */
    public static function fromModel(
        SalesInvoice $invoice,
        bool $includeLines = true,
        ?array $chainIssue = null,
        ?array $chainStatus = null,
    ): array {
        $invoice->loadMissing([
            'customer:id,customer_code,name,phone,status,is_system,salesman_id,payment_method_id,payment_terms_id,wallet_address,wallet_type',
            'warehouse:id,name,shortcut_name,is_active',
            'currency:id,code,name,symbol',
            'salesman:id,full_name,salesman_code',
            'paymentMethod:id,name,code',
            'paymentTerm:id,name,code,due_days',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
            'replacesInvoice:id,invoice_number,status',
            'replacedByInvoice:id,invoice_number,status,replaces_invoice_id',
        ]);

        $payload = [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'customer_id' => $invoice->customer_id,
            'warehouse_id' => $invoice->warehouse_id,
            'currency_id' => $invoice->currency_id,
            'salesman_id' => $invoice->salesman_id,
            'payment_method_id' => $invoice->payment_method_id,
            'payment_terms_id' => $invoice->payment_terms_id,
            'status' => $invoice->status instanceof SalesInvoiceStatus
                ? $invoice->status->value
                : (string) $invoice->status,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'exchange_rate' => (string) $invoice->exchange_rate,
            'reference_2' => $invoice->reference_2,
            'billing_address' => $invoice->billing_address,
            'shipping_address' => $invoice->shipping_address,
            'subtotal' => (string) $invoice->subtotal,
            'discount_total' => (string) $invoice->discount_total,
            'tax_total' => (string) $invoice->tax_total,
            'adjustment' => (string) $invoice->adjustment,
            'grand_total' => (string) $invoice->grand_total,
            'paid_total' => (string) $invoice->paid_total,
            'credited_total' => (string) $invoice->credited_total,
            'net_to_pay' => (string) $invoice->net_to_pay,
            'notes' => $invoice->notes,
            'customer' => self::customerBrief($invoice->customer),
            'warehouse' => self::warehouseBrief($invoice->warehouse),
            'currency' => self::currencyBrief($invoice->currency),
            'salesman' => self::salesmanBrief($invoice->salesman),
            'payment_method' => self::paymentMethodBrief($invoice->paymentMethod),
            'payment_term' => self::paymentTermBrief($invoice->paymentTerm),
            'created_by' => self::userBrief($invoice->createdByUser),
            'posted_by' => self::userBrief($invoice->postedByUser),
            'posted_at' => $invoice->posted_at?->toIso8601String(),
            'replaces_invoice_id' => $invoice->replaces_invoice_id,
            'replaces_invoice' => self::invoiceLinkBrief($invoice->replacesInvoice),
            'replaced_by_invoice' => self::invoiceLinkBrief($invoice->replacedByInvoice),
            'can_reverse' => self::canReverse($invoice),
            'can_reissue' => self::canReissue($invoice),
            'lines_count' => $invoice->lines_count ?? null,
            'chain_issue' => $chainIssue,
            'chain_status' => $chainStatus,
            'created_at' => (string) $invoice->created_at,
            'updated_at' => (string) $invoice->updated_at,
        ];

        if ($includeLines) {
            $invoice->loadMissing([
                'lines.item.itemType',
                'lines.itemUom.uom',
                'lines.warehouse',
                'lines.lot',
            ]);
            $payload['lines'] = SalesInvoiceLineResponseData::collectionToArray($invoice->lines);
            $invoice->loadMissing(['creditNotes']);
            $payload['credit_notes'] = $invoice->creditNotes
                ->map(static fn ($note): array => [
                    'id' => (string) $note->id,
                    'credit_note_number' => $note->credit_note_number,
                    'status' => $note->status instanceof \BackedEnum ? $note->status->value : (string) $note->status,
                    'grand_total' => (string) $note->grand_total,
                ])
                ->values()
                ->all();
        }

        return self::applySealedSnapshot($invoice, $payload);
    }

    /**
     * Posted proof text replaces live master data. Payment balances stay live.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function applySealedSnapshot(SalesInvoice $invoice, array $payload): array
    {
        $invoice->loadMissing('snapshot');
        $canonical = self::canonicalArray($invoice->snapshot);
        if ($canonical === null) {
            return $payload;
        }

        foreach (['invoice_number', 'invoice_date', 'due_on', 'exchange_rate', 'notes', 'subtotal', 'discount_total', 'tax_total', 'adjustment', 'grand_total'] as $key) {
            if (array_key_exists($key, $canonical)) {
                $payload[$key] = $canonical[$key];
            }
        }

        if (is_array($payload['customer'] ?? null) && is_array($canonical['buyer'] ?? null)) {
            $payload['customer']['name'] = $canonical['buyer']['name'] ?? $payload['customer']['name'];
        }

        if (is_array($payload['currency'] ?? null) && is_string($canonical['currency_code'] ?? null)) {
            $payload['currency']['code'] = $canonical['currency_code'];
        }

        $payload['billing_address'] = self::sealedAddress($canonical['billing_address'] ?? null, $invoice->billing_address);
        $payload['shipping_address'] = self::sealedAddress($canonical['shipping_address'] ?? null, $invoice->shipping_address);
        self::overlayNamed($payload, 'warehouse', $canonical['warehouse'] ?? null, ['name', 'shortcut_name']);
        self::overlayNamed($payload, 'salesman', $canonical['salesman'] ?? null, ['code', 'name']);
        self::overlayNamed($payload, 'payment_method', $canonical['payment_method'] ?? null, ['code', 'name']);
        self::overlayNamed($payload, 'payment_term', $canonical['payment_terms'] ?? null, ['code', 'name', 'due_days']);

        if (is_array($payload['lines'] ?? null) && is_array($canonical['lines'] ?? null)) {
            foreach ($payload['lines'] as $index => $line) {
                if (! is_array($line) || ! is_array($canonical['lines'][$index] ?? null)) {
                    continue;
                }
                $payload['lines'][$index] = self::sealedLine($line, $canonical['lines'][$index]);
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function canonicalArray(?InvoiceSnapshot $snapshot): ?array
    {
        if ($snapshot === null || ! is_string($snapshot->canonical_json) || $snapshot->canonical_json === '') {
            return null;
        }

        try {
            $canonical = json_decode($snapshot->canonical_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($canonical) ? $canonical : null;
    }

    /**
     * @param  array<string, mixed>|null  $live
     * @return array<string, mixed>|null
     */
    private static function sealedAddress(mixed $sealed, mixed $live): ?array
    {
        if (! is_array($sealed)) {
            return null;
        }

        $liveId = is_array($live) ? ($live['id'] ?? null) : null;

        return [
            'id' => $liveId,
            'address_line_1' => $sealed['address_line_1'] ?? '',
            'address_line_2' => $sealed['address_line_2'] ?? null,
            'city' => $sealed['city'] ?? '',
            'state' => $sealed['state'] ?? '',
            'country' => $sealed['country'] ?? '',
            'phone' => $sealed['phone'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private static function overlayNamed(array &$payload, string $payloadKey, mixed $sealed, array $keys): void
    {
        if (! is_array($sealed) || ! is_array($payload[$payloadKey] ?? null)) {
            return;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $sealed)) {
                $payload[$payloadKey][$key] = $sealed[$key];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $sealed
     * @return array<string, mixed>
     */
    private static function sealedLine(array $line, array $sealed): array
    {
        foreach (['quantity', 'unit_price', 'discount_percent', 'discount_amount', 'tax_rate', 'line_subtotal', 'tax_amount', 'line_total', 'description', 'notes'] as $key) {
            if (array_key_exists($key, $sealed)) {
                $line[$key] = $sealed[$key];
            }
        }

        if (is_array($line['item'] ?? null)) {
            if (array_key_exists('item_code', $sealed)) {
                $line['item']['item_code'] = $sealed['item_code'];
            }
            if (array_key_exists('item_name', $sealed)) {
                $line['item']['name'] = $sealed['item_name'];
            }
        }

        if (is_array($line['item_uom']['uom'] ?? null) && array_key_exists('uom_code', $sealed)) {
            $line['item_uom']['uom']['code'] = $sealed['uom_code'];
        }

        if (is_array($line['warehouse'] ?? null) && is_array($sealed['warehouse'] ?? null)) {
            $line['warehouse']['name'] = $sealed['warehouse']['name'] ?? $line['warehouse']['name'];
            $line['warehouse']['shortcut_name'] = $sealed['warehouse']['shortcut_name'] ?? $line['warehouse']['shortcut_name'];
        }

        if (is_array($line['lot'] ?? null) && is_array($sealed['lot'] ?? null)) {
            $line['lot']['lot_number'] = $sealed['lot']['lot_number'] ?? $line['lot']['lot_number'];
            $line['lot']['expiry_date'] = $sealed['lot']['expiry_date'] ?? $line['lot']['expiry_date'];
        }

        return $line;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function customerBrief(?Customer $customer): ?array
    {
        if (! $customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'status' => $customer->status instanceof \BackedEnum ? $customer->status->value : (string) $customer->status,
            'is_system' => (bool) $customer->is_system,
            'wallet_address' => $customer->wallet_address,
            'wallet_type' => $customer->wallet_type?->value,
        ];
    }

    /**
     * @return array{id:int,name:string,shortcut_name:?string,is_active:bool}|null
     */
    private static function warehouseBrief(?Warehouse $warehouse): ?array
    {
        if (! $warehouse) {
            return null;
        }

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'shortcut_name' => $warehouse->shortcut_name,
            'is_active' => (bool) $warehouse->is_active,
        ];
    }

    /**
     * @return array{id:int,code:string,name:string,symbol:?string}|null
     */
    private static function currencyBrief(?Currency $currency): ?array
    {
        if (! $currency) {
            return null;
        }

        return [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
        ];
    }

    /**
     * @return array{id:string,name:?string,code:?string}|null
     */
    private static function salesmanBrief(?Salesman $salesman): ?array
    {
        if (! $salesman) {
            return null;
        }

        return [
            'id' => $salesman->id,
            'name' => $salesman->full_name ?? null,
            'code' => $salesman->salesman_code ?? null,
        ];
    }

    /**
     * @return array{id:int,name:?string,code:?string}|null
     */
    private static function paymentMethodBrief(?PaymentMethod $method): ?array
    {
        if (! $method) {
            return null;
        }

        return [
            'id' => $method->id,
            'name' => $method->name ?? null,
            'code' => $method->code ?? null,
        ];
    }

    /**
     * @return array{id:int,name:?string,code:?string,due_days:int}|null
     */
    private static function paymentTermBrief(?PaymentTerm $term): ?array
    {
        if (! $term) {
            return null;
        }

        return [
            'id' => $term->id,
            'name' => $term->name ?? null,
            'code' => $term->code ?? null,
            'due_days' => (int) $term->due_days,
        ];
    }

    /**
     * @return array{id:string,name:string,email:?string}|null
     */
    private static function userBrief(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    /**
     * @return array{id: string, invoice_number: ?string, status: ?string}|null
     */
    private static function invoiceLinkBrief(?SalesInvoice $invoice): ?array
    {
        if (! $invoice) {
            return null;
        }

        $status = $invoice->status instanceof SalesInvoiceStatus
            ? $invoice->status->value
            : (is_string($invoice->status) ? $invoice->status : null);

        return [
            'id' => (string) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $status,
        ];
    }

    private static function canReverse(SalesInvoice $invoice): bool
    {
        return $invoice->status === SalesInvoiceStatus::Posted
            && bccomp((string) $invoice->paid_total, '0', 4) <= 0
            && bccomp((string) ($invoice->credited_total ?? 0), '0', 4) <= 0;
    }

    private static function canReissue(SalesInvoice $invoice): bool
    {
        if (! in_array($invoice->status, [SalesInvoiceStatus::Posted, SalesInvoiceStatus::Reversed], true)) {
            return false;
        }
        if (bccomp((string) $invoice->paid_total, '0', 4) > 0) {
            return false;
        }
        if (bccomp((string) ($invoice->credited_total ?? 0), '0', 4) > 0) {
            return false;
        }

        return $invoice->replacedByInvoice === null;
    }
}
