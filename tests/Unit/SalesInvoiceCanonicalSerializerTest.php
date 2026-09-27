<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\InvoiceProof\CanonicalInvoiceSchema;
use App\Modules\InvoiceProof\DTOs\CanonicalInvoiceData;
use App\Modules\InvoiceProof\DTOs\CanonicalInvoiceLineData;
use App\Modules\InvoiceProof\DTOs\CanonicalPartyData;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Serializers\SalesInvoiceCanonicalSerializer;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceHasher;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Warehouse\Models\Warehouse;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Checks schema v1 shape, sales→supplier/buyer mapping, stable JSON, line sort order,
 * sealed warehouse and address text, and that payment balances stay out of the hash.
 */
class SalesInvoiceCanonicalSerializerTest extends TestCase
{
    private const PROOF_ID = '11111111-1111-4111-8111-111111111111';

    public function test_schema_supports_sales_and_purchase_types(): void
    {
        $this->assertSame(['sales', 'purchase'], InvoiceProofType::values());
        $this->assertSame('purchase', $this->invoiceData(invoiceType: InvoiceProofType::Purchase)->toArray()['invoice_type']);
    }

    public function test_proof_id_must_be_a_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->invoiceData(proofId: 'INV-001');
    }

    public function test_json_uses_schema_v1_key_order(): void
    {
        $payload = $this->invoiceData()->toArray();

        $this->assertSame(CanonicalInvoiceSchema::ROOT_KEYS, array_keys($payload));
        $this->assertSame(CanonicalInvoiceSchema::PARTY_KEYS, array_keys($payload['supplier']));
        $this->assertSame(CanonicalInvoiceSchema::PARTY_KEYS, array_keys($payload['buyer']));
        $this->assertSame(CanonicalInvoiceSchema::LINE_KEYS, array_keys($payload['lines'][0]));
        $this->assertSame(CanonicalInvoiceSchema::VERSION, $payload['schema_version']);
    }

    public function test_sales_invoice_maps_company_to_supplier_and_customer_to_buyer(): void
    {
        $payload = SalesInvoiceCanonicalSerializer::serialize(
            $this->salesInvoice(),
            self::PROOF_ID,
            $this->company(),
        )->toArray();

        $this->assertSame('sales', $payload['invoice_type']);
        $this->assertSame(self::PROOF_ID, $payload['proof_id']);
        $this->assertSame('Acme Trading', $payload['supplier']['name']);
        $this->assertSame('Acme Trading LLC', $payload['supplier']['legal_name']);
        $this->assertSame('TAX-100', $payload['supplier']['tax_number']);
        $this->assertSame('Buyer Co', $payload['buyer']['name']);
        $this->assertSame('VAT-200', $payload['buyer']['tax_number']);
        $this->assertNull($payload['buyer']['legal_name']);
        $this->assertSame('Widget', $payload['lines'][0]['item_name']);
        $this->assertSame('Widget', $payload['lines'][0]['description']);
    }

    public function test_same_sales_invoice_serializes_to_the_same_json(): void
    {
        $invoice = $this->salesInvoice();
        $company = $this->company();

        $first = SalesInvoiceCanonicalSerializer::serialize($invoice, self::PROOF_ID, $company)->toJson();
        $second = SalesInvoiceCanonicalSerializer::serialize($invoice, self::PROOF_ID, $company)->toJson();

        $this->assertSame($first, $second);
        $this->assertSame(CanonicalInvoiceHasher::sha256($first), CanonicalInvoiceHasher::sha256($second));
        $this->assertSame(
            '{"schema_version":1,"proof_id":"11111111-1111-4111-8111-111111111111","invoice_type":"sales","invoice_number":"INV-0001","invoice_date":"2026-09-06","due_on":"2026-10-06","currency_code":"USD","exchange_rate":"1.000000","supplier":{"name":"Acme Trading","legal_name":"Acme Trading LLC","tax_number":"TAX-100","email":"acme@example.com"},"buyer":{"name":"Buyer Co","legal_name":null,"tax_number":"VAT-200","email":"buyer@example.com"},"salesman":null,"payment_method":null,"payment_terms":null,"warehouse":{"name":"Main Warehouse","shortcut_name":null},"billing_address":null,"shipping_address":null,"lines":[{"sort_order":0,"item_code":"SKU-1","item_name":"Widget","description":"Widget","uom_code":"PCS","quantity":"2.000000","unit_price":"10.0000","discount_percent":"0.0000","discount_amount":"0.0000","tax_rate":"10.0000","line_subtotal":"20.0000","tax_amount":"2.0000","line_total":"22.0000","warehouse":null,"lot":null,"notes":null}],"subtotal":"20.0000","discount_total":"0.0000","tax_total":"2.0000","adjustment":"0.0000","grand_total":"22.0000","notes":null}',
            $first,
        );
    }

    public function test_lines_are_sorted_by_sort_order_then_id(): void
    {
        $second = $this->line(id: 10, sortOrder: 1, itemCode: 'SKU-B', description: 'Second');
        $first = $this->line(id: 20, sortOrder: 0, itemCode: 'SKU-A', description: 'First');
        $invoice = $this->salesInvoice(lines: [$second, $first]);

        $payload = SalesInvoiceCanonicalSerializer::serialize($invoice, self::PROOF_ID, $this->company())->toArray();

        $this->assertSame(['SKU-A', 'SKU-B'], array_column($payload['lines'], 'item_code'));
    }

    public function test_line_seals_item_name_separately_from_description(): void
    {
        $line = $this->line();
        $line->item->name = 'Demo Item';
        $line->item->description = 'General information portal';
        $line->description = 'General information portal';

        $payload = SalesInvoiceCanonicalSerializer::serialize(
            $this->salesInvoice(lines: [$line]),
            self::PROOF_ID,
            $this->company(),
        )->toArray();

        $this->assertSame('Demo Item', $payload['lines'][0]['item_name']);
        $this->assertSame('General information portal', $payload['lines'][0]['description']);
    }

    public function test_changing_price_quantity_or_date_changes_json(): void
    {
        $company = $this->company();
        $base = SalesInvoiceCanonicalSerializer::serialize($this->salesInvoice(), self::PROOF_ID, $company)->toJson();

        $priceChanged = $this->salesInvoice();
        $priceChanged->lines[0]->unit_price = 11.0;
        $this->assertNotSame($base, SalesInvoiceCanonicalSerializer::serialize($priceChanged, self::PROOF_ID, $company)->toJson());

        $qtyChanged = $this->salesInvoice();
        $qtyChanged->lines[0]->quantity = 3.0;
        $this->assertNotSame($base, SalesInvoiceCanonicalSerializer::serialize($qtyChanged, self::PROOF_ID, $company)->toJson());

        $dateChanged = $this->salesInvoice();
        $dateChanged->invoice_date = Carbon::parse('2026-09-07');
        $this->assertNotSame($base, SalesInvoiceCanonicalSerializer::serialize($dateChanged, self::PROOF_ID, $company)->toJson());
        $this->assertNotSame(
            CanonicalInvoiceHasher::sha256($base),
            CanonicalInvoiceHasher::sha256(SalesInvoiceCanonicalSerializer::serialize($dateChanged, self::PROOF_ID, $company)->toJson()),
        );
    }

    public function test_display_and_changing_fields_are_not_included(): void
    {
        $json = SalesInvoiceCanonicalSerializer::serialize($this->salesInvoice(), self::PROOF_ID, $this->company())->toJson();
        $payload = json_decode($json, true);

        $this->assertArrayNotHasKey('status', $payload);
        $this->assertArrayNotHasKey('paid_total', $payload);
        $this->assertArrayNotHasKey('net_to_pay', $payload);
        $this->assertArrayNotHasKey('customer_id', $payload);
        $this->assertArrayNotHasKey('created_at', $payload);
        $this->assertArrayNotHasKey('updated_at', $payload);
        $this->assertArrayNotHasKey('posted_at', $payload);
        $this->assertArrayNotHasKey('posted_by', $payload);
        $this->assertSame('Main Warehouse', $payload['warehouse']['name']);
        $this->assertNull($payload['billing_address']);
        $this->assertNull($payload['shipping_address']);
        $this->assertNull($payload['lines'][0]['lot']);
        $this->assertNull($payload['lines'][0]['notes']);
    }

    public function test_billing_address_and_line_notes_are_sealed(): void
    {
        $invoice = $this->salesInvoice();
        $invoice->billing_address = [
            'id' => 9,
            'address_line_1' => '12 Market',
            'address_line_2' => '',
            'city' => 'Beirut',
            'state' => '',
            'country' => 'LB',
            'phone' => '01000000',
        ];
        $invoice->lines[0]->notes = 'Keep cold';

        $payload = SalesInvoiceCanonicalSerializer::serialize($invoice, self::PROOF_ID, $this->company())->toArray();

        $this->assertSame(CanonicalInvoiceSchema::ADDRESS_KEYS, array_keys($payload['billing_address']));
        $this->assertSame('12 Market', $payload['billing_address']['address_line_1']);
        $this->assertSame('Beirut', $payload['billing_address']['city']);
        $this->assertArrayNotHasKey('id', $payload['billing_address']);
        $this->assertSame('Keep cold', $payload['lines'][0]['notes']);
    }

    /**
     * @param  list<SalesInvoiceLine>|null  $lines
     */
    private function salesInvoice(?array $lines = null): SalesInvoice
    {
        $invoice = new SalesInvoice;
        $invoice->invoice_number = 'INV-0001';
        $invoice->invoice_date = Carbon::parse('2026-09-06');
        $invoice->due_on = Carbon::parse('2026-10-06');
        $invoice->exchange_rate = 1.0;
        $invoice->subtotal = 20.0;
        $invoice->discount_total = 0.0;
        $invoice->tax_total = 2.0;
        $invoice->adjustment = 0.0;
        $invoice->grand_total = 22.0;
        $invoice->paid_total = 5.0;
        $invoice->net_to_pay = 17.0;
        $invoice->notes = '';
        $invoice->status = SalesInvoiceStatus::Posted;

        $customer = new Customer;
        $customer->name = 'Buyer Co';
        $customer->vat_number = 'VAT-200';
        $customer->email = 'buyer@example.com';

        $currency = new Currency;
        $currency->code = 'USD';

        $warehouse = new Warehouse;
        $warehouse->name = 'Main Warehouse';

        $invoice->setRelation('customer', $customer);
        $invoice->setRelation('currency', $currency);
        $invoice->setRelation('warehouse', $warehouse);
        $invoice->setRelation('lines', collect($lines ?? [$this->line()]));

        return $invoice;
    }

    private function line(int $id = 1, int $sortOrder = 0, string $itemCode = 'SKU-1', string $description = 'Widget'): SalesInvoiceLine
    {
        $line = new SalesInvoiceLine;
        $line->id = $id;
        $line->sort_order = $sortOrder;
        $line->quantity = 2.0;
        $line->unit_price = 10.0;
        $line->discount_percent = 0.0;
        $line->discount_amount = 0.0;
        $line->tax_rate = 10.0;
        $line->line_subtotal = 20.0;
        $line->tax_amount = 2.0;
        $line->line_total = 22.0;
        $line->description = $description;

        $item = new Item;
        $item->item_code = $itemCode;
        $item->name = $description;

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

    private function invoiceData(
        string $proofId = self::PROOF_ID,
        InvoiceProofType $invoiceType = InvoiceProofType::Sales,
    ): CanonicalInvoiceData {
        return new CanonicalInvoiceData(
            proofId: $proofId,
            invoiceType: $invoiceType,
            invoiceNumber: 'INV-0001',
            invoiceDate: '2026-09-06',
            dueOn: '2026-10-06',
            currencyCode: 'USD',
            exchangeRate: '1',
            supplier: new CanonicalPartyData('Acme', null, 'TAX-1', null),
            buyer: new CanonicalPartyData('Buyer', null, null, null),
            lines: [
                new CanonicalInvoiceLineData(
                    sortOrder: 0,
                    itemCode: 'SKU-1',
                    itemName: 'Widget',
                    description: 'Widget',
                    uomCode: 'PCS',
                    quantity: '1',
                    unitPrice: '10',
                    discountPercent: '0',
                    discountAmount: '0',
                    taxRate: '0',
                    lineSubtotal: '10',
                    taxAmount: '0',
                    lineTotal: '10',
                ),
            ],
            subtotal: '10',
            discountTotal: '0',
            taxTotal: '0',
            adjustment: '0',
            grandTotal: '10',
            notes: null,
        );
    }
}
