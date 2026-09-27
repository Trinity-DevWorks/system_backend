<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Support\InvoiceProofBytes;
use App\Modules\InvoiceProof\Support\InvoiceRegistryAbi;
use App\Modules\InvoiceProof\Support\WalletAddress;
use InvalidArgumentException;
use Tests\TestCase;

class InvoiceProofBytesTest extends TestCase
{
    public function test_proof_id_is_uuid_hex_right_padded_to_bytes32(): void
    {
        $this->assertSame(
            '0x1111111111114111811111111111111100000000000000000000000000000000',
            InvoiceProofBytes::proofIdToBytes32('11111111-1111-4111-8111-111111111111'),
        );
    }

    public function test_content_hash_keeps_64_hex_chars(): void
    {
        $hash = str_repeat('ab', 32);

        $this->assertSame('0x'.$hash, InvoiceProofBytes::contentHashToBytes32($hash));
        $this->assertSame($hash, InvoiceProofBytes::normalizedContentHash('0x'.$hash));
    }

    public function test_register_calldata_starts_with_selector(): void
    {
        $data = InvoiceRegistryAbi::encodeRegisterInvoice(
            '11111111-1111-4111-8111-111111111111',
            str_repeat('ab', 32),
            '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
            '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC',
        );

        $this->assertStringStartsWith(InvoiceRegistryAbi::REGISTER_INVOICE, $data);
        $this->assertSame(10 + 64 + 64 + 64 + 64, strlen($data));
    }

    public function test_set_parties_calldata_starts_with_selector(): void
    {
        $data = InvoiceRegistryAbi::encodeSetParties(
            '11111111-1111-4111-8111-111111111111',
            '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
            WalletAddress::ZERO,
        );

        $this->assertStringStartsWith(InvoiceRegistryAbi::SET_PARTIES, $data);
        $this->assertSame(10 + 64 + 64 + 64, strlen($data));
        $this->assertStringEndsWith(str_repeat('0', 64), $data);
    }

    public function test_approve_and_invoices_calldata_use_proof_id(): void
    {
        $proofId = '11111111-1111-4111-8111-111111111111';
        $padded = InvoiceProofBytes::strip0x(InvoiceProofBytes::proofIdToBytes32($proofId));

        $this->assertSame(
            InvoiceRegistryAbi::INVOICES.$padded,
            InvoiceRegistryAbi::encodeInvoices($proofId),
        );
    }

    public function test_approve_by_supplier_calldata_encodes_invoice_number(): void
    {
        $proofId = '11111111-1111-4111-8111-111111111111';
        $hash = str_repeat('ab', 32);

        $calldata = InvoiceRegistryAbi::encodeApproveBySupplier($proofId, $hash, 'INV-0001', 'Approve invoice INV-0001 from Acme.');

        $this->assertStringStartsWith('0x1d97ab7e', $calldata);
        $this->assertStringContainsString(bin2hex('Approve invoice INV-0001 from Acme.'), $calldata);
    }

    public function test_approve_by_buyer_calldata_encodes_typed_approval(): void
    {
        $proofId = '11111111-1111-4111-8111-111111111111';
        $hash = str_repeat('ab', 32);
        $signature = '0x112233445566778899001122334455667788990011223344556677889900112233';

        $calldata = InvoiceRegistryAbi::encodeApproveByBuyer(
            $proofId,
            $hash,
            'INV-0001',
            'Approve invoice INV-0001 from Acme.',
            $signature,
        );

        $this->assertStringStartsWith('0x0a3e2d36', $calldata);
        $this->assertStringContainsString(bin2hex('Approve invoice INV-0001 from Acme.'), $calldata);
    }

    public function test_buyer_approval_typed_data_uses_contract_domain(): void
    {
        $typed = InvoiceRegistryAbi::buyerApprovalTypedData(
            31337,
            '0x5FbDB2315678afecb367f032d93F642f64180aa3',
            '11111111-1111-4111-8111-111111111111',
            str_repeat('ab', 32),
            'INV-0001',
            'Approve invoice INV-0001 from Acme.',
        );

        $this->assertSame('InvoiceRegistry', $typed['domain']['name']);
        $this->assertSame('1', $typed['domain']['version']);
        $this->assertSame(31337, $typed['domain']['chain_id']);
        $this->assertSame('0x5fbdb2315678afecb367f032d93f642f64180aa3', $typed['domain']['verifying_contract']);
        $this->assertSame('BuyerApproval', $typed['primary_type']);
        $this->assertSame('INV-0001', $typed['message']['invoice_number']);
        $this->assertSame('Approve invoice INV-0001 from Acme.', $typed['message']['statement']);
        $this->assertSame(
            '0x1111111111114111811111111111111100000000000000000000000000000000',
            $typed['message']['proof_id'],
        );
    }

    public function test_supplier_approval_typed_data_uses_contract_domain(): void
    {
        $typed = InvoiceRegistryAbi::supplierApprovalTypedData(
            31337,
            '0x5FbDB2315678afecb367f032d93F642f64180aa3',
            '11111111-1111-4111-8111-111111111111',
            str_repeat('ab', 32),
            'INV-0001',
            'Approve invoice INV-0001 from Acme.',
        );

        $this->assertSame('InvoiceRegistry', $typed['domain']['name']);
        $this->assertSame('1', $typed['domain']['version']);
        $this->assertSame(31337, $typed['domain']['chain_id']);
        $this->assertSame('0x5fbdb2315678afecb367f032d93f642f64180aa3', $typed['domain']['verifying_contract']);
        $this->assertSame('SupplierApproval', $typed['primary_type']);
        $this->assertSame('INV-0001', $typed['message']['invoice_number']);
        $this->assertSame('Approve invoice INV-0001 from Acme.', $typed['message']['statement']);
        $this->assertArrayHasKey('SupplierApproval', $typed['types']);
    }

    public function test_decode_invoice_maps_registered_record(): void
    {
        $hash = str_repeat('ab', 32);
        $supplier = str_repeat('0', 24).'70997970c51812dc3a010c7d01b50e0d17dc79c8';
        $buyer = str_repeat('0', 24).'3c44cdddb6a900fa2b585dd299e03d12fa4293bc';
        $false = str_repeat('0', 64);
        $registeredAt = str_pad(dechex(1700000000), 64, '0', STR_PAD_LEFT);
        $supplierApprovedAt = str_pad(dechex(1700001000), 64, '0', STR_PAD_LEFT);
        $data = '0x'.$hash.$supplier.$buyer.$false.$false.$registeredAt.$supplierApprovedAt.$false;

        $record = InvoiceRegistryAbi::decodeInvoice($data);

        $this->assertNotNull($record);
        $this->assertSame($hash, $record->contentHash);
        $this->assertSame('0x70997970c51812dc3a010c7d01b50e0d17dc79c8', $record->supplierAddress);
        $this->assertSame('0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc', $record->buyerAddress);
        $this->assertFalse($record->supplierApproved);
        $this->assertFalse($record->buyerApproved);
        $this->assertSame(1, $record->status->value);
        $this->assertSame(1700000000, $record->registeredAt);
        $this->assertSame(1700001000, $record->supplierApprovedAt);
        $this->assertNull($record->buyerApprovedAt);
    }

    public function test_zero_bytes32_decodes_as_missing(): void
    {
        $this->assertNull(InvoiceRegistryAbi::decodeBytes32('0x'.str_repeat('0', 64)));
        $this->assertSame(str_repeat('ab', 32), InvoiceRegistryAbi::decodeBytes32('0x'.str_repeat('ab', 32)));
    }

    public function test_invalid_uuid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceProofBytes::proofIdToBytes32('INV-001');
    }
}
