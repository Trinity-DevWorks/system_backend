<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerLedgerEntry;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=CustomerReceiptApiTest
 */
#[Group('tenant-db')]
class CustomerReceiptApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('customer_receipt_v1');
        BootstrapTenantItemTypes::dispatchSync($this->tenant);
        BootstrapTenantUnitCatalog::dispatchSync($this->tenant);
        $this->token = $this->tenantBearerToken();
        $this->catalog = $this->tenant->run(fn (): array => $this->seedCatalog());
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_partial_receipt_reduces_invoice_open_amount_and_customer_balance(): void
    {
        $invoiceId = $this->postSalesInvoice(100);

        $receiptId = $this->createAndPostReceipt($this->receiptPayload($invoiceId, '40'));

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customer-receipts/'.$receiptId))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->tenant->run(function () use ($invoiceId): void {
            $invoice = SalesInvoice::query()->findOrFail($invoiceId);
            $this->assertSame('posted', $invoice->status->value);
            $this->assertEqualsWithDelta(40.0, (float) $invoice->paid_total, 0.001);
            $this->assertEqualsWithDelta(60.0, (float) $invoice->net_to_pay, 0.001);

            $balance = app(CustomerLedgerService::class)->balanceInCurrency(
                Customer::query()->findOrFail($this->catalog['customer_id']),
                $this->catalog['usd_id'],
            );
            $this->assertEqualsWithDelta(60.0, (float) $balance, 0.001);

            $entry = CustomerLedgerEntry::query()
                ->where('reference_type', LedgerReferenceType::Payment)
                ->whereNotNull('reference_id')
                ->first();
            $this->assertNotNull($entry);
            $this->assertEqualsWithDelta(0.0, (float) $entry->debit, 0.001);
            $this->assertEqualsWithDelta(40.0, (float) $entry->credit, 0.001);
        });
    }

    public function test_one_receipt_can_allocate_two_invoices(): void
    {
        $first = $this->postSalesInvoice(40);
        $second = $this->postSalesInvoice(60);

        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), [
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $this->catalog['usd_id'],
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-09-02',
                'amount' => 100,
                'allocations' => [
                    ['sales_invoice_id' => $first, 'amount' => 40],
                    ['sales_invoice_id' => $second, 'amount' => 60],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.receipt_number', 'CR-000001')
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$id}/post"))
            ->assertOk();

        $this->tenant->run(function () use ($first, $second): void {
            $this->assertEqualsWithDelta(0.0, (float) SalesInvoice::query()->findOrFail($first)->net_to_pay, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) SalesInvoice::query()->findOrFail($second)->net_to_pay, 0.001);
        });
    }

    public function test_second_receipt_clears_the_remainder_and_leaves_the_invoice_posted(): void
    {
        $invoiceId = $this->postSalesInvoice(100);
        $this->createAndPostReceipt($this->receiptPayload($invoiceId, '40'));
        $this->createAndPostReceipt($this->receiptPayload($invoiceId, '60'));

        $this->tenant->run(function () use ($invoiceId): void {
            $invoice = SalesInvoice::query()->findOrFail($invoiceId);
            $this->assertSame('posted', $invoice->status->value);
            $this->assertEqualsWithDelta(100.0, (float) $invoice->paid_total, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $invoice->net_to_pay, 0.001);
        });

        $open = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customer-receipts/open-invoices?customer_id='.$this->catalog['customer_id'].'&currency_id='.$this->catalog['usd_id']))
            ->assertOk()
            ->json('data');

        $this->assertSame([], $open);
    }

    public function test_over_allocation_rolls_back_without_a_ledger_entry(): void
    {
        $invoiceId = $this->postSalesInvoice(100);
        $receiptId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), $this->receiptPayload($invoiceId, '150'))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');

        $this->tenant->run(function () use ($invoiceId): void {
            $invoice = SalesInvoice::query()->findOrFail($invoiceId);
            $this->assertEqualsWithDelta(0.0, (float) $invoice->paid_total, 0.001);
            $this->assertEqualsWithDelta(100.0, (float) $invoice->net_to_pay, 0.001);
            $this->assertSame(0, CustomerLedgerEntry::query()->where('reference_type', LedgerReferenceType::Payment)->count());
        });
    }

    public function test_rejects_closed_invoices_wrong_party_and_unbalanced_header(): void
    {
        $postedId = $this->postSalesInvoice(100);

        $draftId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->invoicePayload(50))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), $this->receiptPayload($draftId, '50'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');

        $reversedId = $this->postSalesInvoice(25);
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$reversedId}/reverse"))
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), $this->receiptPayload($reversedId, '25'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), $this->receiptPayload($postedId, '40', [
                'customer_id' => $this->catalog['other_customer_id'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');

        $unbalanced = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), $this->receiptPayload($postedId, '40', [
                'amount' => 50,
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$unbalanced}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_RECEIPT_ALLOCATION_MISMATCH');
    }

    public function test_primary_currency_receipt_settles_a_foreign_invoice(): void
    {
        $this->tenant->run(function (): void {
            app(ExchangeRateService::class)->setPairRate($this->catalog['usd_id'], $this->catalog['eur_id'], 3.5);
        });

        $invoiceId = $this->postSalesInvoice(350, [
            'currency_id' => $this->catalog['eur_id'],
            'exchange_rate' => 3.5,
        ]);

        $openIds = collect($this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customer-receipts/open-invoices?customer_id='.$this->catalog['customer_id'].'&currency_id='.$this->catalog['usd_id']))
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->all();
        $this->assertContains($invoiceId, $openIds);

        $receiptId = $this->createAndPostReceipt($this->receiptPayload($invoiceId, '100'));

        $this->tenant->run(function () use ($invoiceId, $receiptId): void {
            $invoice = SalesInvoice::query()->findOrFail($invoiceId);
            $this->assertEqualsWithDelta(350.0, (float) $invoice->paid_total, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $invoice->net_to_pay, 0.001);

            $customer = Customer::query()->findOrFail($this->catalog['customer_id']);
            $ledger = app(CustomerLedgerService::class);
            $this->assertEqualsWithDelta(0.0, (float) $ledger->balanceInCurrency($customer, $this->catalog['eur_id']), 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $ledger->balanceInCurrency($customer, $this->catalog['usd_id']), 0.001);

            $entry = CustomerLedgerEntry::query()
                ->where('reference_type', LedgerReferenceType::Payment)
                ->where('reference_id', $receiptId)
                ->first();
            $this->assertNotNull($entry);
            $this->assertSame($this->catalog['eur_id'], (int) $entry->currency_id);
            $this->assertEqualsWithDelta(350.0, (float) $entry->credit, 0.001);
        });

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/reverse"))
            ->assertOk();

        $overId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), $this->receiptPayload($invoiceId, '200'))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$overId}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');

        $this->tenant->run(function () use ($invoiceId): void {
            $invoice = SalesInvoice::query()->findOrFail($invoiceId);
            $this->assertEqualsWithDelta(0.0, (float) $invoice->paid_total, 0.001);
            $this->assertEqualsWithDelta(350.0, (float) $invoice->net_to_pay, 0.001);
        });
    }

    public function test_rejects_credit_methods_and_missing_references(): void
    {
        $invoiceId = $this->postSalesInvoice(100);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), $this->receiptPayload($invoiceId, '40', [
                'payment_method_id' => $this->catalog['credit_method_id'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_METHOD_NOT_ALLOWED');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), $this->receiptPayload($invoiceId, '40', [
                'payment_method_id' => $this->catalog['cheque_method_id'],
                'reference' => '',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_REFERENCE_REQUIRED');
    }

    public function test_reverse_restores_the_invoice_and_then_the_invoice_can_be_reversed(): void
    {
        $invoiceId = $this->postSalesInvoice(100);
        $receiptId = $this->createAndPostReceipt($this->receiptPayload($invoiceId, '40'));

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_HAS_PAYMENTS');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->tenant->run(function () use ($invoiceId): void {
            $invoice = SalesInvoice::query()->findOrFail($invoiceId);
            $this->assertEqualsWithDelta(0.0, (float) $invoice->paid_total, 0.001);
            $this->assertEqualsWithDelta(100.0, (float) $invoice->net_to_pay, 0.001);

            $balance = app(CustomerLedgerService::class)->balanceInCurrency(
                Customer::query()->findOrFail($this->catalog['customer_id']),
                $this->catalog['usd_id'],
            );
            $this->assertEqualsWithDelta(100.0, (float) $balance, 0.001);
        });

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');
    }

    public function test_delete_posted_and_reverse_draft_are_rejected(): void
    {
        $invoiceId = $this->postSalesInvoice(100);
        $postedId = $this->createAndPostReceipt($this->receiptPayload($invoiceId, '40'));

        $this->asTenantRequest($this->token)
            ->deleteJson($this->tenantUrl("/customer-receipts/{$postedId}"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_RECEIPT_NOT_DRAFT');

        $draftId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), $this->receiptPayload($invoiceId, '10'))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$draftId}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_RECEIPT_NOT_POSTED');
    }

    private function postSalesInvoice(float $amount, array $overrides = []): string
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->invoicePayload($amount, $overrides))
            ->assertCreated()
            ->json('data.id');

        $posted = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk();

        $this->assertEqualsWithDelta($amount, (float) $posted->json('data.grand_total'), 0.001);

        return (string) $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoicePayload(float $amount, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->catalog['customer_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'currency_id' => $this->catalog['usd_id'],
            'invoice_date' => '2026-09-01',
            'lines' => [[
                'item_id' => $this->catalog['service_item_id'],
                'quantity' => 1,
                'unit_price' => $amount,
            ]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function receiptPayload(string $invoiceId, string $amount, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->catalog['customer_id'],
            'currency_id' => $this->catalog['usd_id'],
            'payment_method_id' => $this->catalog['cash_method_id'],
            'payment_date' => '2026-09-02',
            'amount' => $amount,
            'allocations' => [[
                'sales_invoice_id' => $invoiceId,
                'amount' => $amount,
            ]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createAndPostReceipt(array $payload): string
    {
        $created = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), $payload)
            ->assertCreated();

        $this->assertMatchesRegularExpression('/^CR-\d{6}$/', (string) $created->json('data.receipt_number'));
        $id = (string) $created->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$id}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCatalog(): array
    {
        $usd = Currency::query()->create([
            'name' => 'US Dollar',
            'code' => 'USD',
            'iso_code' => 'USD',
            'symbol' => '$',
            'is_active' => true,
        ]);
        $eur = Currency::query()->create([
            'name' => 'Euro',
            'code' => 'EUR',
            'iso_code' => 'EUR',
            'symbol' => '€',
            'is_active' => true,
        ]);

        CompanySetting::singleton()->update([
            'primary_currency_id' => $usd->id,
            'tax_enabled' => false,
            'allow_negative_stock' => false,
        ]);
        app(CompanySettingService::class)->forgetCache();

        $warehouse = Warehouse::query()->create([
            'name' => 'Sales WH',
            'shortcut_name' => 'SWH',
            'type' => WarehouseType::Central,
            'manager_id' => $this->tenantUser->id,
            'is_active' => true,
            'is_default' => true,
            'is_default_sales' => true,
            'is_default_production' => false,
            'is_default_purchase' => false,
            'is_default_storage' => false,
        ]);

        $vat = VatGroup::query()->create([
            'abrv' => 'VAT0',
            'name' => 'VAT 0',
            'percentage' => 0,
            'is_default' => true,
            'is_active' => true,
        ]);

        $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
        $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
        $serviceType = ItemType::query()->where('code', 'SERVICE')->firstOrFail();

        $service = Item::query()->create([
            'name' => 'Service item',
            'item_code' => 'SVC-1',
            'item_type_id' => $serviceType->id,
            'unit_group_id' => $unitGroup->id,
            'base_uom_id' => $uom->id,
            'vat_group_id' => $vat->id,
            'track_inventory' => false,
            'track_lots' => false,
            'allow_sale' => true,
            'allow_purchase' => true,
            'is_active' => true,
        ]);
        ItemUom::query()->create([
            'item_id' => $service->id,
            'uom_id' => $uom->id,
            'conversion_factor' => 1,
            'selling_price' => 10,
            'cost_price' => 5,
            'is_base' => true,
            'is_default_sale' => true,
        ]);

        $customer = Customer::query()->create([
            'name' => 'Invoice Customer',
            'type' => 'individual',
            'status' => 'active',
        ]);
        $other = Customer::query()->create([
            'name' => 'Other Customer',
            'type' => 'individual',
            'status' => 'active',
        ]);

        $cash = PaymentMethod::query()->create([
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'requires_reference' => false,
            'is_active' => true,
        ]);
        $credit = PaymentMethod::query()->create([
            'code' => 'CREDIT',
            'name' => 'On account',
            'type' => 'credit',
            'requires_reference' => false,
            'is_active' => true,
        ]);
        $cheque = PaymentMethod::query()->create([
            'code' => 'CHQ',
            'name' => 'Cheque',
            'type' => 'cheque',
            'requires_reference' => true,
            'is_active' => true,
        ]);

        return [
            'usd_id' => (int) $usd->id,
            'eur_id' => (int) $eur->id,
            'warehouse_id' => (int) $warehouse->id,
            'customer_id' => (string) $customer->id,
            'other_customer_id' => (string) $other->id,
            'service_item_id' => (string) $service->id,
            'cash_method_id' => (int) $cash->id,
            'credit_method_id' => (int) $credit->id,
            'cheque_method_id' => (int) $cheque->id,
        ];
    }
}
