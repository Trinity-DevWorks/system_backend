<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceMerkle;
use App\Modules\InvoiceProof\Support\InvoiceProofBytes;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Supplier\Models\Supplier;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Turns a supplier disclosure file into the purchase-invoice fields that must
 * stay equal to that seal. The buyer's warehouse is not part of the seal.
 */
class LinkedPurchaseDisclosureService
{
    public function __construct(
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
    ) {}

    /**
     * @param  array<string, mixed>  $disclosure
     * @return array<string, mixed>
     */
    public function import(array $disclosure): array
    {
        $built = $this->build($disclosure);
        $proofId = (string) $built['seal']['proof_id'];
        if (PurchaseInvoice::query()->where('linked_proof_id', $proofId)->exists()) {
            abort(422, 'This supplier proof is already linked to another purchase invoice.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_PROOF_IN_USE',
            ]);
        }

        return $built['form'];
    }

    /**
     * @param  array<string, mixed>|null  $disclosure
     * @param  array<string, mixed>|null  $storedSeal
     * @return array<string, mixed>|null
     */
    public function sealFor(string $proofId, ?array $disclosure, ?array $storedSeal): array
    {
        if (is_array($disclosure)) {
            $seal = $this->build($disclosure)['seal'];
            if (($seal['proof_id'] ?? null) !== $proofId) {
                abort(422, 'The disclosure is for a different proof.', [
                    'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_DISCLOSURE_MISMATCH',
                ]);
            }

            return $seal;
        }

        if (is_array($storedSeal) && ($storedSeal['proof_id'] ?? null) === $proofId) {
            return $storedSeal;
        }

        abort(422, 'Upload the supplier disclosure for this proof.', [
            'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_DISCLOSURE_REQUIRED',
        ]);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $seal
     */
    public function assertHeaderMatches(array $header, array $seal): void
    {
        if (($header['goods_receipt_id'] ?? null) !== null || ($header['purchase_order_id'] ?? null) !== null) {
            $this->mismatch();
        }
        if ((string) ($header['supplier_id'] ?? '') !== (string) ($seal['supplier_id'] ?? '')) {
            $this->mismatch();
        }
        if ((int) ($header['currency_id'] ?? 0) !== (int) ($seal['currency_id'] ?? 0)) {
            $this->mismatch();
        }
        if ((string) ($header['invoice_date'] ?? '') !== (string) ($seal['invoice_date'] ?? '')) {
            $this->mismatch();
        }
        if (! $this->sameDecimal($header['exchange_rate'] ?? null, $seal['exchange_rate'] ?? null, 12)) {
            $this->mismatch();
        }
        if (($seal['lock_due_on'] ?? false) === true && (string) ($header['due_on'] ?? '') !== (string) ($seal['due_on'] ?? '')) {
            $this->mismatch();
        }
        if (($seal['lock_adjustment'] ?? false) === true && ! $this->sameDecimal($header['adjustment'] ?? null, $seal['adjustment'] ?? null, 4)) {
            $this->mismatch();
        }
        if (($seal['lock_notes'] ?? false) === true && ($header['notes'] ?? null) !== ($seal['notes'] ?? null)) {
            $this->mismatch();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>|null  $seal
     */
    public function assertLinesMatch(?array $seal, array $lines): void
    {
        if (! is_array($seal)) {
            return;
        }

        $expected = $seal['lines'] ?? null;
        if (! is_array($expected) || count($expected) !== count($lines)) {
            $this->mismatch();
        }

        foreach (array_values($lines) as $index => $row) {
            $sealed = $expected[$index];
            if (! is_array($sealed)) {
                $this->mismatch();
            }
            if ((string) ($row['item_id'] ?? '') !== (string) ($sealed['item_id'] ?? '')) {
                $this->mismatch();
            }
            if ((int) ($row['item_uom_id'] ?? 0) !== (int) ($sealed['item_uom_id'] ?? 0)) {
                $this->mismatch();
            }
            if (! $this->sameDecimal($row['quantity'] ?? null, $sealed['quantity'] ?? null, 6)) {
                $this->mismatch();
            }
            if (! $this->sameDecimal($row['unit_price'] ?? null, $sealed['unit_price'] ?? null, 4)) {
                $this->mismatch();
            }
            $discount = $row['discount_percent'] ?? 0;
            if (! $this->sameDecimal($discount, $sealed['discount_percent'] ?? null, 4)) {
                $this->mismatch();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $disclosure
     * @return array{seal: array<string, mixed>, form: array<string, mixed>}
     */
    private function build(array $disclosure): array
    {
        $proofId = strtolower(trim((string) ($disclosure['proof_id'] ?? '')));
        $fields = $disclosure['fields'] ?? null;
        if ($proofId === '' || ! is_array($fields)) {
            $this->incomplete();
        }

        $onChain = $this->invoiceChainRegistrationService->assertExternalProofLink($proofId);
        try {
            $expected = InvoiceProofBytes::normalizedContentHash((string) ($disclosure['content_hash'] ?? ''));
            $actual = InvoiceProofBytes::normalizedContentHash($onChain->contentHash);
        } catch (InvalidArgumentException) {
            $this->mismatchDisclosure();
        }
        if (! hash_equals($expected, $actual)) {
            $this->mismatchDisclosure();
        }

        $values = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['path'] ?? null)) {
                $this->mismatchDisclosure();
            }
            $proof = $field['proof'] ?? null;
            if (! is_array($proof) || ! is_string($field['salt'] ?? null)) {
                $this->mismatchDisclosure();
            }
            /** @var list<array{position: string, hash: string}> $proof */
            if (! CanonicalInvoiceMerkle::verify($field['path'], $field['value'] ?? null, $field['salt'], $proof, $actual)) {
                $this->mismatchDisclosure();
            }
            $values[$field['path']] = $field['value'] ?? null;
        }

        $supplier = $this->matchSupplier($values, WalletAddress::normalize($onChain->supplierAddress));
        $currency = $this->matchCurrency($this->requireText($values, 'currency_code'));
        $lines = $this->matchLines($values);
        $invoiceDate = $this->requireDate($values, 'invoice_date');
        $exchangeRate = $this->requireDecimal($values, 'exchange_rate', 12);
        $lockNotes = array_key_exists('notes', $values);
        $notes = $lockNotes ? $this->optionalText($values, 'notes') : null;
        $lockAdjustment = array_key_exists('adjustment', $values);
        $adjustment = $lockAdjustment ? $this->decimalOrZero($values, 'adjustment', 4) : $this->decimal('0', 4);
        $lockDueOn = array_key_exists('due_on', $values) && $values['due_on'] !== null && $values['due_on'] !== '';
        $dueOn = $lockDueOn ? $this->requireDate($values, 'due_on') : null;

        $sealLines = [];
        $formLines = [];
        foreach ($lines as $line) {
            $sealLines[] = [
                'item_id' => $line['item_id'],
                'item_uom_id' => $line['item_uom_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount_percent' => $line['discount_percent'],
            ];
            $formLines[] = $line;
        }

        $seal = [
            'proof_id' => $proofId,
            'supplier_id' => (string) $supplier->id,
            'currency_id' => (int) $currency->id,
            'invoice_date' => $invoiceDate,
            'due_on' => $dueOn,
            'lock_due_on' => $lockDueOn,
            'exchange_rate' => $exchangeRate,
            'adjustment' => $adjustment,
            'lock_adjustment' => $lockAdjustment,
            'notes' => $notes,
            'lock_notes' => $lockNotes,
            'lines' => $sealLines,
        ];

        return [
            'seal' => $seal,
            'form' => [
                'proof_id' => $proofId,
                'supplier_id' => (string) $supplier->id,
                'supplier_name' => (string) $supplier->name,
                'currency_id' => (int) $currency->id,
                'invoice_date' => $invoiceDate,
                'due_on' => $dueOn,
                'exchange_rate' => $exchangeRate,
                'adjustment' => $adjustment,
                'notes' => $notes,
                'lines' => $formLines,
            ],
        ];
    }

    /**
     * The disclosed supplier is the seller's company. Match the on-chain wallet
     * first, then the name, and only then the tax number. A tax number that is
     * not stored on the supplier card must not hide a name match.
     *
     * @param  array<string, mixed>  $values
     */
    private function matchSupplier(array $values, ?string $supplierWallet): Supplier
    {
        $names = [];
        foreach (['supplier.name', 'supplier.legal_name'] as $path) {
            $text = $this->optionalText($values, $path);
            if ($text !== null) {
                $names[] = mb_strtolower($text);
            }
        }
        if ($names === [] && $supplierWallet === null) {
            $this->incomplete();
        }

        $tax = $this->normalizeTax($this->optionalText($values, 'supplier.tax_number'));
        $bestScore = 0;
        $best = [];
        foreach (Supplier::query()->where('is_active', true)->get() as $supplier) {
            $score = 0;
            try {
                $wallet = WalletAddress::normalize($supplier->wallet_address);
            } catch (InvalidArgumentException) {
                $wallet = null;
            }
            if ($supplierWallet !== null && $wallet === $supplierWallet) {
                $score += 8;
            }
            foreach ([$supplier->name, $supplier->company_name] as $stored) {
                $needle = is_string($stored) ? mb_strtolower(trim($stored)) : '';
                if ($needle !== '' && in_array($needle, $names, true)) {
                    $score += 2;
                    break;
                }
            }
            $storedTax = $this->normalizeTax(is_string($supplier->vat_number) ? $supplier->vat_number : null);
            if ($tax !== null && $storedTax === $tax) {
                $score += 1;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$supplier];
            } elseif ($score > 0 && $score === $bestScore) {
                $best[] = $supplier;
            }
        }

        if (count($best) !== 1) {
            abort(422, 'No single active supplier matches this disclosure.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_SUPPLIER_NOT_FOUND',
            ]);
        }

        return $best[0];
    }

    private function normalizeTax(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = strtolower((string) preg_replace('/[\s\-]/', '', $value));

        return $text === '' ? null : $text;
    }

    private function matchCurrency(string $code): Currency
    {
        $currency = Currency::query()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->first();
        if ($currency === null) {
            abort(422, 'This currency is not in your company.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_CURRENCY_NOT_FOUND',
            ]);
        }

        return $currency;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function matchLines(array $values): array
    {
        $indexes = [];
        foreach (array_keys($values) as $path) {
            if (preg_match('/^lines\.(\d+)\.item_code$/', (string) $path, $match) === 1) {
                $indexes[] = (int) $match[1];
            }
        }
        sort($indexes);
        if ($indexes === []) {
            $this->incomplete();
        }

        $lines = [];
        foreach ($indexes as $index) {
            $code = $this->requireText($values, 'lines.'.$index.'.item_code');
            $item = Item::query()->with('itemUoms.uom')->where('item_code', $code)->where('is_active', true)->first();
            if ($item === null) {
                abort(422, 'Item '.$code.' from this disclosure is not in your catalog.', [
                    'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_ITEM_NOT_FOUND',
                ]);
            }
            $uomCode = $this->optionalText($values, 'lines.'.$index.'.uom_code');
            $itemUom = null;
            foreach ($item->itemUoms as $candidate) {
                $candidateCode = $candidate->uom?->code;
                if ($uomCode !== null && is_string($candidateCode) && strcasecmp($candidateCode, $uomCode) === 0) {
                    $itemUom = $candidate;
                    break;
                }
            }
            if ($itemUom === null) {
                foreach ($item->itemUoms as $candidate) {
                    if ($candidate->is_base) {
                        $itemUom = $candidate;
                        break;
                    }
                }
            }
            if ($itemUom === null) {
                abort(422, 'Item '.$code.' has no matching unit.', [
                    'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_ITEM_NOT_FOUND',
                ]);
            }

            $itemName = $this->optionalText($values, 'lines.'.$index.'.item_name') ?? (string) $item->name;
            $lines[] = [
                'item_id' => (string) $item->id,
                'item_label' => $code.' — '.$itemName,
                'item_uom_id' => (int) $itemUom->id,
                'quantity' => $this->requireDecimal($values, 'lines.'.$index.'.quantity', 6),
                'unit_price' => $this->requireDecimal($values, 'lines.'.$index.'.unit_price', 4),
                'discount_percent' => $this->decimalOrZero($values, 'lines.'.$index.'.discount_percent', 4),
                'description' => $this->optionalText($values, 'lines.'.$index.'.description'),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requireText(array $values, string $path): string
    {
        if (! array_key_exists($path, $values) || ! is_string($values[$path]) || trim($values[$path]) === '') {
            $this->incomplete();
        }

        return trim($values[$path]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function optionalText(array $values, string $path): ?string
    {
        if (! array_key_exists($path, $values) || $values[$path] === null) {
            return null;
        }
        if (! is_string($values[$path])) {
            $this->incomplete();
        }
        $text = trim($values[$path]);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requireDate(array $values, string $path): string
    {
        $text = $this->requireText($values, $path);
        try {
            return Carbon::parse($text)->toDateString();
        } catch (\Throwable) {
            $this->incomplete();
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requireDecimal(array $values, string $path, int $scale): string
    {
        if (! array_key_exists($path, $values) || $values[$path] === null || $values[$path] === '') {
            $this->incomplete();
        }

        return $this->decimal((string) $values[$path], $scale);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function decimalOrZero(array $values, string $path, int $scale): string
    {
        if (! array_key_exists($path, $values) || $values[$path] === null || $values[$path] === '') {
            return $this->decimal('0', $scale);
        }

        return $this->decimal((string) $values[$path], $scale);
    }

    private function decimal(string $value, int $scale): string
    {
        if (! is_numeric($value)) {
            $this->incomplete();
        }

        return bcadd($value, '0', $scale);
    }

    private function sameDecimal(mixed $left, mixed $right, int $scale): bool
    {
        if ($left === null || $right === null || ! is_numeric((string) $left) || ! is_numeric((string) $right)) {
            return false;
        }

        return bccomp(bcadd((string) $left, '0', $scale), bcadd((string) $right, '0', $scale), $scale) === 0;
    }

    private function incomplete(): never
    {
        abort(422, 'The supplier disclosure is missing invoice fields.', [
            'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_DISCLOSURE_INCOMPLETE',
        ]);
    }

    private function mismatch(): never
    {
        abort(422, 'Sealed invoice fields cannot be changed. The warehouse can.', [
            'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_SEAL_MISMATCH',
        ]);
    }

    private function mismatchDisclosure(): never
    {
        abort(422, 'This disclosure does not match the proof on the blockchain.', [
            'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_DISCLOSURE_MISMATCH',
        ]);
    }
}
