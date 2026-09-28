<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\InvoiceProof\Contracts\InvoiceRegistryGateway;
use App\Modules\InvoiceProof\DTOs\InvoiceAttestationRecord;
use App\Modules\InvoiceProof\DTOs\InvoiceOnChainRecord;
use App\Modules\InvoiceProof\Enums\InvoiceOnChainStatus;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Enums\InvoiceProofVerificationStatus;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Serializers\SalesInvoiceCanonicalSerializer;
use App\Modules\InvoiceProof\Services\InvoiceChainRegistrationService;
use App\Modules\InvoiceProof\Services\InvoiceProofVerificationService;
use App\Modules\InvoiceProof\Services\InvoiceSnapshotService;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceHasher;
use App\Modules\InvoiceProof\Support\InvoiceApprovalStatement;
use App\Modules\InvoiceProof\Support\InvoiceRegistryAbi;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Checks proof status from the snapshot hash versus the chain hash.
 */
class InvoiceProofVerificationServiceTest extends TestCase
{
    private const PROOF_ID = '11111111-1111-4111-8111-111111111111';

    private InvoiceProofVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new InvoiceProofVerificationService(
            $this->createMock(InvoiceSnapshotService::class),
            $this->createMock(InvoiceChainRegistrationService::class),
            $this->createMock(InvoiceRegistryGateway::class),
        );
    }

    public function test_missing_snapshot_is_not_registered(): void
    {
        $result = $this->service->evaluateSalesInvoice($this->salesInvoice(), null, $this->company());

        $this->assertSame(InvoiceProofVerificationStatus::NotRegistered, $result->status);
        $this->assertNull($result->snapshotIntact);
        $this->assertNull($result->liveInvoiceMatches);
        $this->assertNull($result->chainMatches);
        $this->assertSame('not_registered', $result->toArray()['status']);
        $this->assertNull($result->toArray()['chain_matches']);
    }

    public function test_matching_snapshot_and_live_invoice_are_verified(): void
    {
        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company());

        $this->assertSame(InvoiceProofVerificationStatus::Verified, $result->status);
        $this->assertTrue($result->snapshotIntact);
        $this->assertTrue($result->liveInvoiceMatches);
        $this->assertNull($result->chainMatches);
    }

    public function test_live_invoice_edits_do_not_change_proof_status(): void
    {
        $company = $this->company();
        $snapshot = $this->snapshotFor($this->salesInvoice());

        $priceChanged = $this->salesInvoice();
        $priceChanged->lines[0]->unit_price = 11.0;
        $priceResult = $this->service->evaluateSalesInvoice($priceChanged, $snapshot, $company);
        $this->assertSame(InvoiceProofVerificationStatus::Verified, $priceResult->status);
        $this->assertTrue($priceResult->snapshotIntact);
        $this->assertFalse($priceResult->liveInvoiceMatches);
        $this->assertNull($priceResult->chainMatches);

        $qtyChanged = $this->salesInvoice();
        $qtyChanged->lines[0]->quantity = 3.0;
        $qtyResult = $this->service->evaluateSalesInvoice($qtyChanged, $snapshot, $company);
        $this->assertSame(InvoiceProofVerificationStatus::Verified, $qtyResult->status);
        $this->assertFalse($qtyResult->liveInvoiceMatches);

        $dateChanged = $this->salesInvoice();
        $dateChanged->invoice_date = Carbon::parse('2026-09-07');
        $dateResult = $this->service->evaluateSalesInvoice($dateChanged, $snapshot, $company);
        $this->assertSame(InvoiceProofVerificationStatus::Verified, $dateResult->status);
        $this->assertFalse($dateResult->liveInvoiceMatches);

        $lineChanged = $this->salesInvoice(lines: [
            $this->line(id: 1, itemCode: 'SKU-CHANGED'),
        ]);
        $lineResult = $this->service->evaluateSalesInvoice($lineChanged, $snapshot, $company);
        $this->assertSame(InvoiceProofVerificationStatus::Verified, $lineResult->status);
        $this->assertFalse($lineResult->liveInvoiceMatches);

        $emailChanged = $this->salesInvoice();
        $emailChanged->customer->email = 'other@example.com';
        $emailResult = $this->service->evaluateSalesInvoice($emailChanged, $snapshot, $company);
        $this->assertSame(InvoiceProofVerificationStatus::Verified, $emailResult->status);
        $this->assertFalse($emailResult->liveInvoiceMatches);
    }

    public function test_live_invoice_edit_keeps_chain_approval_status(): void
    {
        config(['blockchain.contract_address' => '0x5FbDB2315678afecb367f032d93F642f64180aa3']);

        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);
        $invoice->lines[0]->unit_price = 11.0;
        $onChain = $this->onChain($snapshot->content_hash, InvoiceOnChainStatus::Registered);

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company(), $onChain, true);

        $this->assertSame(InvoiceProofVerificationStatus::WaitingCompany, $result->status);
        $this->assertTrue($result->chainMatches);
        $this->assertFalse($result->liveInvoiceMatches);
        $this->assertTrue($result->canApproveAsCompany);
    }

    public function test_edited_snapshot_json_is_tampered(): void
    {
        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);
        $snapshot->canonical_json = str_replace('10.0000', '99.0000', $snapshot->canonical_json);

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company());

        $this->assertSame(InvoiceProofVerificationStatus::Tampered, $result->status);
        $this->assertFalse($result->snapshotIntact);
        $this->assertTrue($result->liveInvoiceMatches);
        $this->assertNull($result->chainMatches);
    }

    public function test_matching_chain_hash_is_verified_when_chain_enabled(): void
    {
        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);

        $result = $this->service->evaluateSalesInvoice(
            $invoice,
            $snapshot,
            $this->company(),
            $snapshot->content_hash,
            true,
        );

        $this->assertSame(InvoiceProofVerificationStatus::Verified, $result->status);
        $this->assertTrue($result->chainMatches);
        $this->assertFalse($result->canApproveAsCompany);
        $this->assertFalse($result->canApproveAsBuyer);
    }

    public function test_matching_registered_chain_record_is_waiting_company(): void
    {
        config(['blockchain.contract_address' => '0x5FbDB2315678afecb367f032d93F642f64180aa3']);

        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);
        $onChain = $this->onChain($snapshot->content_hash, InvoiceOnChainStatus::Registered, registeredAt: 1700000000);

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company(), $onChain, true);

        $this->assertSame(InvoiceProofVerificationStatus::WaitingCompany, $result->status);
        $this->assertSame('2023-11-14T22:13:20+00:00', $result->registeredAt);
        $this->assertNull($result->supplierApprovedAt);
        $this->assertNull($result->buyerApprovedAt);
        $this->assertTrue($result->canApproveAsCompany);
        $this->assertFalse($result->canApproveAsBuyer);
        $this->assertSame($onChain->supplierAddress, $result->supplierWallet);
        $this->assertSame($onChain->buyerAddress, $result->buyerWallet);
        $this->assertSame(self::PROOF_ID, $result->proofId);
        $this->assertSame('0x5fbdb2315678afecb367f032d93f642f64180aa3', $result->contractAddress);
        $this->assertIsArray($result->eip712);
        $this->assertSame('SupplierApproval', $result->eip712['primary_type'] ?? null);
        $this->assertSame('anvil', $result->blockchainNetwork);
        $this->assertNull($result->safeTxServiceUrl);
        $this->assertSame('INV-0001', $result->eip712['message']['invoice_number'] ?? null);
        $this->assertStringContainsString('Acme Trading', (string) ($result->eip712['message']['statement'] ?? ''));
        $this->assertSame(
            InvoiceRegistryAbi::supplierApprovalTypedData(
                (int) config('blockchain.chain_id'),
                '0x5FbDB2315678afecb367f032d93F642f64180aa3',
                self::PROOF_ID,
                $snapshot->content_hash,
                'INV-0001',
                InvoiceApprovalStatement::approve('Acme Trading', 'INV-0001', '22.0000', 'USD'),
            ),
            $result->eip712,
        );
    }

    public function test_registered_without_supplier_is_verified_not_waiting_company(): void
    {
        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);
        $onChain = new InvoiceOnChainRecord(
            contentHash: $snapshot->content_hash,
            supplierAddress: WalletAddress::ZERO,
            buyerAddress: WalletAddress::ZERO,
            supplierApproved: false,
            buyerApproved: false,
            status: InvoiceOnChainStatus::Registered,
        );

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company(), $onChain, true);

        $this->assertSame(InvoiceProofVerificationStatus::Verified, $result->status);
        $this->assertTrue($result->chainMatches);
        $this->assertFalse($result->canApproveAsCompany);
        $this->assertFalse($result->canApproveAsBuyer);
        $this->assertNull($result->supplierWallet);
        $this->assertNull($result->buyerWallet);
    }

    public function test_supplier_approved_without_buyer_is_verified_not_waiting_buyer(): void
    {
        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);
        $onChain = new InvoiceOnChainRecord(
            contentHash: $snapshot->content_hash,
            supplierAddress: '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            buyerAddress: WalletAddress::ZERO,
            supplierApproved: true,
            buyerApproved: false,
            status: InvoiceOnChainStatus::SupplierApproved,
        );

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company(), $onChain, true);

        $this->assertSame(InvoiceProofVerificationStatus::Verified, $result->status);
        $this->assertFalse($result->canApproveAsCompany);
        $this->assertFalse($result->canApproveAsBuyer);
        $this->assertSame('0x70997970c51812dc3a010c7d01b50e0d17dc79c8', $result->supplierWallet);
        $this->assertNull($result->buyerWallet);
    }

    public function test_supplier_approved_chain_record_is_waiting_buyer(): void
    {
        config(['blockchain.contract_address' => '0x5FbDB2315678afecb367f032d93F642f64180aa3']);

        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);
        $onChain = $this->onChain($snapshot->content_hash, InvoiceOnChainStatus::SupplierApproved);

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company(), $onChain, true);

        $this->assertSame(InvoiceProofVerificationStatus::WaitingBuyer, $result->status);
        $this->assertFalse($result->canApproveAsCompany);
        $this->assertTrue($result->canApproveAsBuyer);
        $this->assertSame(self::PROOF_ID, $result->proofId);
        $this->assertSame('0x5fbdb2315678afecb367f032d93f642f64180aa3', $result->contractAddress);
        $this->assertIsArray($result->eip712);
        $this->assertSame('BuyerApproval', $result->eip712['primary_type'] ?? null);
        $this->assertSame('INV-0001', $result->eip712['message']['invoice_number'] ?? null);
        $this->assertStringContainsString('Acme Trading', (string) ($result->eip712['message']['statement'] ?? ''));
        $this->assertSame(
            InvoiceRegistryAbi::buyerApprovalTypedData(
                (int) config('blockchain.chain_id'),
                '0x5FbDB2315678afecb367f032d93F642f64180aa3',
                self::PROOF_ID,
                $snapshot->content_hash,
                'INV-0001',
                InvoiceApprovalStatement::approve('Acme Trading', 'INV-0001', '22.0000', 'USD'),
            ),
            $result->eip712,
        );
    }

    public function test_fully_approved_chain_record_is_fully_approved(): void
    {
        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);
        $onChain = $this->onChain($snapshot->content_hash, InvoiceOnChainStatus::FullyApproved);

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company(), $onChain, true);

        $this->assertSame(InvoiceProofVerificationStatus::FullyApproved, $result->status);
        $this->assertFalse($result->canApproveAsCompany);
        $this->assertFalse($result->canApproveAsBuyer);
    }

    public function test_missing_chain_hash_is_pending_when_chain_enabled(): void
    {
        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company(), null, true);

        $this->assertSame(InvoiceProofVerificationStatus::PendingChain, $result->status);
        $this->assertTrue($result->snapshotIntact);
        $this->assertTrue($result->liveInvoiceMatches);
        $this->assertNull($result->chainMatches);
    }

    public function test_different_chain_hash_is_tampered(): void
    {
        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);

        $result = $this->service->evaluateSalesInvoice(
            $invoice,
            $snapshot,
            $this->company(),
            str_repeat('ab', 32),
            true,
        );

        $this->assertSame(InvoiceProofVerificationStatus::Tampered, $result->status);
        $this->assertFalse($result->chainMatches);
    }

    public function test_attestations_are_exposed_with_financier(): void
    {
        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);
        $onChain = $this->onChain($snapshot->content_hash, InvoiceOnChainStatus::FullyApproved);
        $attestations = [
            new InvoiceAttestationRecord('0x90f79bf6eb2c4f870365e785982e1f101e93b906', InvoiceVerifierRole::Auditor, null, 1700000000),
            new InvoiceAttestationRecord('0x15d34aaf54267db7d7c367839aaf71a00a2c6a65', InvoiceVerifierRole::Financier, str_repeat('cd', 32), 1700000100),
        ];

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company(), $onChain, true, $attestations);
        $payload = $result->toArray();

        $this->assertSame('0x15d34aaf54267db7d7c367839aaf71a00a2c6a65', $payload['financed_by']);
        $this->assertCount(2, $payload['attestations']);
        $this->assertSame([
            'verifier' => '0x90f79bf6eb2c4f870365e785982e1f101e93b906',
            'verifier_name' => null,
            'role' => 'auditor',
            'reference_hash' => null,
            'attested_at' => '2023-11-14T22:13:20+00:00',
        ], $payload['attestations'][0]);
        $this->assertSame('financier', $payload['attestations'][1]['role']);
    }

    public function test_attestations_are_dropped_without_chain_record(): void
    {
        $invoice = $this->salesInvoice();
        $snapshot = $this->snapshotFor($invoice);
        $attestations = [
            new InvoiceAttestationRecord('0x15d34aaf54267db7d7c367839aaf71a00a2c6a65', InvoiceVerifierRole::Financier, null, 1700000100),
        ];

        $result = $this->service->evaluateSalesInvoice($invoice, $snapshot, $this->company(), null, true, $attestations);

        $this->assertSame([], $result->toArray()['attestations']);
        $this->assertNull($result->toArray()['financed_by']);
    }

    public function test_attestation_abi_round_trip(): void
    {
        $this->assertSame(
            '0x4ed051db'.'11111111111141118111111111111111'.str_repeat('0', 32).str_repeat('0', 63).'2',
            InvoiceRegistryAbi::encodeAttestationAt(self::PROOF_ID, 2),
        );

        $data = '0x'
            .str_repeat('0', 24).'15d34aaf54267db7d7c367839aaf71a00a2c6a65'
            .str_repeat('0', 63).'3'
            .str_repeat('cd', 32)
            .str_pad(dechex(1700000100), 64, '0', STR_PAD_LEFT);

        $record = InvoiceRegistryAbi::decodeAttestation($data);

        $this->assertNotNull($record);
        $this->assertSame('0x15d34aaf54267db7d7c367839aaf71a00a2c6a65', $record->verifier);
        $this->assertSame(InvoiceVerifierRole::Financier, $record->role);
        $this->assertSame(str_repeat('cd', 32), $record->referenceHash);
        $this->assertSame(1700000100, $record->attestedAt);
        $this->assertSame(3, InvoiceRegistryAbi::decodeUint('0x'.str_repeat('0', 63).'3'));
        $this->assertSame(
            '0x2b70a025'
                .str_repeat('0', 24).'5fbdb2315678afecb367f032d93f642f64180aa3'
                .str_repeat('0', 24).'15d34aaf54267db7d7c367839aaf71a00a2c6a65'
                .str_repeat('0', 63).'3',
            InvoiceRegistryAbi::encodeSetVerifier(
                '0x5FbDB2315678afecb367f032d93F642f64180aa3',
                '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
                InvoiceVerifierRole::Financier->toChain(),
            ),
        );
    }

    private function onChain(string $contentHash, InvoiceOnChainStatus $status, ?int $registeredAt = null): InvoiceOnChainRecord
    {
        return new InvoiceOnChainRecord(
            contentHash: $contentHash,
            supplierAddress: '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            buyerAddress: '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
            supplierApproved: $status !== InvoiceOnChainStatus::Registered,
            buyerApproved: $status === InvoiceOnChainStatus::FullyApproved,
            status: $status,
            registeredAt: $registeredAt,
        );
    }

    private function snapshotFor(SalesInvoice $invoice): InvoiceSnapshot
    {
        $json = SalesInvoiceCanonicalSerializer::serialize($invoice, self::PROOF_ID, $this->company())->toJson();

        $snapshot = new InvoiceSnapshot;
        $snapshot->id = self::PROOF_ID;
        $snapshot->invoice_type = InvoiceProofType::Sales;
        $snapshot->canonical_json = $json;
        $snapshot->disclosure_secret = str_repeat('ab', 32);
        $snapshot->content_hash = CanonicalInvoiceHasher::hash($json, $snapshot->disclosure_secret);

        return $snapshot;
    }

    /**
     * @param  list<SalesInvoiceLine>|null  $lines
     */
    private function salesInvoice(?array $lines = null): SalesInvoice
    {
        $invoice = new SalesInvoice;
        $invoice->id = '22222222-2222-4222-8222-222222222222';
        $invoice->invoice_number = 'INV-0001';
        $invoice->invoice_date = Carbon::parse('2026-09-06');
        $invoice->due_on = Carbon::parse('2026-10-06');
        $invoice->exchange_rate = 1.0;
        $invoice->subtotal = 20.0;
        $invoice->discount_total = 0.0;
        $invoice->tax_total = 2.0;
        $invoice->adjustment = 0.0;
        $invoice->grand_total = 22.0;
        $invoice->status = SalesInvoiceStatus::Posted;

        $customer = new Customer;
        $customer->name = 'Buyer Co';
        $customer->vat_number = 'VAT-200';
        $customer->email = 'buyer@example.com';

        $currency = new Currency;
        $currency->code = 'USD';

        $invoice->setRelation('customer', $customer);
        $invoice->setRelation('currency', $currency);
        $invoice->setRelation('lines', collect($lines ?? [$this->line()]));

        return $invoice;
    }

    private function line(int $id = 1, string $itemCode = 'SKU-1'): SalesInvoiceLine
    {
        $line = new SalesInvoiceLine;
        $line->id = $id;
        $line->sort_order = 0;
        $line->quantity = 2.0;
        $line->unit_price = 10.0;
        $line->discount_percent = 0.0;
        $line->discount_amount = 0.0;
        $line->tax_rate = 10.0;
        $line->line_subtotal = 20.0;
        $line->tax_amount = 2.0;
        $line->line_total = 22.0;
        $line->description = 'Widget';

        $item = new Item;
        $item->item_code = $itemCode;
        $item->name = 'Widget';

        $uom = new UnitOfMeasurement;
        $uom->code = 'PCS';
        $itemUom = new ItemUom;
        $itemUom->setRelation('uom', $uom);

        $line->setRelation('item', $item);
        $line->setRelation('itemUom', $itemUom);

        return $line;
    }

    private function company(): CompanyProfile
    {
        $company = new CompanyProfile;
        $company->company_name = 'Acme Trading';
        $company->legal_name = 'Acme Trading LLC';
        $company->tax_number = 'TAX-100';
        $company->email = 'acme@example.com';

        return $company;
    }
}
