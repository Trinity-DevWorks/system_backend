<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Supplier\Enums\LedgerReferenceType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierLedgerEntry;
use App\Modules\Supplier\Services\SupplierLedgerService;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=SupplierPaymentApiTest
 */
#[Group('tenant-db')]
class SupplierPaymentApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('supplier_payment_v1');
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

    public function test_partial_payment_reduces_invoice_open_amount_and_supplier_balance(): void
    {
        $invoiceId = $this->postPurchaseInvoice(100);
        $this->createAndPostPayment($this->paymentPayload($invoiceId, '40'));

        $this->tenant->run(function () use ($invoiceId): void {
            $invoice = PurchaseInvoice::query()->findOrFail($invoiceId);
            $this->assertSame('posted', $invoice->status->value);
            $this->assertEqualsWithDelta(40.0, (float) $invoice->paid_total, 0.001);
            $this->assertEqualsWithDelta(60.0, (float) $invoice->net_to_pay, 0.001);

            $balance = app(SupplierLedgerService::class)->balanceInCurrency(
                Supplier::query()->findOrFail($this->catalog['supplier_id']),
                $this->catalog['usd_id'],
            );
            $this->assertEqualsWithDelta(60.0, (float) $balance, 0.001);

            $entry = SupplierLedgerEntry::query()
                ->where('reference_type', LedgerReferenceType::Payment)
                ->first();
            $this->assertNotNull($entry);
            $this->assertEqualsWithDelta(40.0, (float) $entry->debit, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $entry->credit, 0.001);
        });
    }

    public function test_one_payment_can_allocate_two_invoices_and_a_second_payment_clears_a_remainder(): void
    {
        $first = $this->postPurchaseInvoice(40);
        $second = $this->postPurchaseInvoice(60);

        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'currency_id' => $this->catalog['usd_id'],
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-09-02',
                'amount' => 100,
                'allocations' => [
                    ['purchase_invoice_id' => $first, 'amount' => 40],
                    ['purchase_invoice_id' => $second, 'amount' => 60],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_number', 'SP-000001')
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/supplier-payments/{$id}/post"))
            ->assertOk();

        $openInvoice = $this->postPurchaseInvoice(100);
        $this->createAndPostPayment($this->paymentPayload($openInvoice, '40'));
        $this->createAndPostPayment($this->paymentPayload($openInvoice, '60'));

        $this->tenant->run(function () use ($first, $second, $openInvoice): void {
            $this->assertEqualsWithDelta(0.0, (float) PurchaseInvoice::query()->findOrFail($first)->net_to_pay, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) PurchaseInvoice::query()->findOrFail($second)->net_to_pay, 0.001);
            $invoice = PurchaseInvoice::query()->findOrFail($openInvoice);
            $this->assertSame('posted', $invoice->status->value);
            $this->assertEqualsWithDelta(0.0, (float) $invoice->net_to_pay, 0.001);
        });
    }

    public function test_rejects_invalid_allocations_methods_and_lifecycle(): void
    {
        $postedId = $this->postPurchaseInvoice(100);

        $draftId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), $this->invoicePayload(50))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), $this->paymentPayload($draftId, '50'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPLIER_PAYMENT_INVOICE_NOT_OPEN');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), $this->paymentPayload($postedId, '40', [
                'supplier_id' => $this->catalog['other_supplier_id'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPLIER_PAYMENT_INVOICE_MISMATCH');

        $overId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), $this->paymentPayload($postedId, '150'))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/supplier-payments/{$overId}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPLIER_PAYMENT_INVOICE_NOT_OPEN');

        $this->tenant->run(function () use ($postedId): void {
            $invoice = PurchaseInvoice::query()->findOrFail($postedId);
            $this->assertEqualsWithDelta(0.0, (float) $invoice->paid_total, 0.001);
            $this->assertSame(0, SupplierLedgerEntry::query()->where('reference_type', LedgerReferenceType::Payment)->count());
        });

        $unbalanced = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), $this->paymentPayload($postedId, '40', [
                'amount' => 50,
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/supplier-payments/{$unbalanced}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPLIER_PAYMENT_ALLOCATION_MISMATCH');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), $this->paymentPayload($postedId, '40', [
                'payment_method_id' => $this->catalog['credit_method_id'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_METHOD_NOT_ALLOWED');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), $this->paymentPayload($postedId, '40', [
                'payment_method_id' => $this->catalog['cheque_method_id'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_REFERENCE_REQUIRED');

        $paidId = $this->createAndPostPayment($this->paymentPayload($postedId, '40'));

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$postedId}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PURCHASE_INVOICE_HAS_PAYMENTS');

        $this->asTenantRequest($this->token)
            ->deleteJson($this->tenantUrl("/supplier-payments/{$paidId}"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPLIER_PAYMENT_NOT_DRAFT');

        $draftPayment = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), $this->paymentPayload($postedId, '10'))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/supplier-payments/{$draftPayment}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPLIER_PAYMENT_NOT_POSTED');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/supplier-payments/{$paidId}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->tenant->run(function () use ($postedId): void {
            $invoice = PurchaseInvoice::query()->findOrFail($postedId);
            $this->assertEqualsWithDelta(0.0, (float) $invoice->paid_total, 0.001);
            $this->assertEqualsWithDelta(100.0, (float) $invoice->net_to_pay, 0.001);
            $balance = app(SupplierLedgerService::class)->balanceInCurrency(
                Supplier::query()->findOrFail($this->catalog['supplier_id']),
                $this->catalog['usd_id'],
            );
            $this->assertEqualsWithDelta(100.0, (float) $balance, 0.001);
        });

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$postedId}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');
    }

    public function test_primary_currency_payment_settles_a_foreign_invoice(): void
    {
        $this->tenant->run(function (): void {
            app(ExchangeRateService::class)->setPairRate($this->catalog['usd_id'], $this->catalog['eur_id'], 3.5);
        });

        $invoiceId = $this->postPurchaseInvoice(350, [
            'currency_id' => $this->catalog['eur_id'],
            'exchange_rate' => 3.5,
        ]);

        $paymentId = $this->createAndPostPayment($this->paymentPayload($invoiceId, '100'));

        $this->tenant->run(function () use ($invoiceId, $paymentId): void {
            $invoice = PurchaseInvoice::query()->findOrFail($invoiceId);
            $this->assertEqualsWithDelta(350.0, (float) $invoice->paid_total, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $invoice->net_to_pay, 0.001);

            $supplier = Supplier::query()->findOrFail($this->catalog['supplier_id']);
            $ledger = app(SupplierLedgerService::class);
            $this->assertEqualsWithDelta(0.0, (float) $ledger->balanceInCurrency($supplier, $this->catalog['eur_id']), 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $ledger->balanceInCurrency($supplier, $this->catalog['usd_id']), 0.001);

            $entry = SupplierLedgerEntry::query()
                ->where('reference_type', LedgerReferenceType::Payment)
                ->where('reference_id', $paymentId)
                ->first();
            $this->assertNotNull($entry);
            $this->assertSame($this->catalog['eur_id'], (int) $entry->currency_id);
            $this->assertEqualsWithDelta(350.0, (float) $entry->debit, 0.001);
        });
    }

    private function postPurchaseInvoice(float $amount, array $overrides = []): string
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), $this->invoicePayload($amount, $overrides))
            ->assertCreated()
            ->json('data.id');

        $posted = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$id}/post"))
            ->assertOk();

        $this->assertEqualsWithDelta($amount, (float) $posted->json('data.grand_total'), 0.001);

        return (string) $id;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoicePayload(float $amount, array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->catalog['supplier_id'],
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
    private function paymentPayload(string $invoiceId, string $amount, array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->catalog['supplier_id'],
            'currency_id' => $this->catalog['usd_id'],
            'payment_method_id' => $this->catalog['cash_method_id'],
            'payment_date' => '2026-09-02',
            'amount' => $amount,
            'allocations' => [[
                'purchase_invoice_id' => $invoiceId,
                'amount' => $amount,
            ]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createAndPostPayment(array $payload): string
    {
        $created = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), $payload)
            ->assertCreated();

        $this->assertMatchesRegularExpression('/^SP-\d{6}$/', (string) $created->json('data.payment_number'));
        $id = (string) $created->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/supplier-payments/{$id}/post"))
            ->assertOk();

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
            'name' => 'Purchase WH',
            'shortcut_name' => 'PWH',
            'type' => WarehouseType::Central,
            'manager_id' => $this->tenantUser->id,
            'is_active' => true,
            'is_default' => true,
            'is_default_sales' => false,
            'is_default_production' => false,
            'is_default_purchase' => true,
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
            'is_default_purchase' => true,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Pay Supplier',
            'is_active' => true,
        ]);
        $other = Supplier::query()->create([
            'name' => 'Other Supplier',
            'is_active' => true,
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
            'supplier_id' => (string) $supplier->id,
            'other_supplier_id' => (string) $other->id,
            'service_item_id' => (string) $service->id,
            'cash_method_id' => (int) $cash->id,
            'credit_method_id' => (int) $credit->id,
            'cheque_method_id' => (int) $cheque->id,
        ];
    }
}
