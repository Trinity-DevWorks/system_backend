<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentPdf;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Accounting\Models\Account;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\RolePermission;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Document PDF endpoints (docs/printing-plan.md, Tests section).
 *
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=DocumentPdf
 *
 * The existing purchase order PDF test lives in PurchaseOrderTotalsApiTest.
 */
#[Group('tenant-db')]
class DocumentPdfApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const CASH_ACCOUNT = '5310';

    private const CAPITAL_ACCOUNT = '1013';

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('document_pdf_v1');
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

    public function test_draft_sales_invoice_pdf_is_rejected(): void
    {
        $id = $this->createSalesInvoice();

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/pdf"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_PDF_NOT_ALLOWED');
    }

    public function test_posted_sales_invoice_pdf_downloads_and_needs_print_permission(): void
    {
        $id = $this->postSalesInvoice();

        $this->assertPdfDownload("/sales-invoices/{$id}/pdf");

        $this->stripPrint('sales_invoices');

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/pdf"))
            ->assertForbidden();
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}"))
            ->assertOk();
    }

    public function test_sales_invoice_pdf_renders_in_arabic(): void
    {
        $id = $this->postSalesInvoice();

        $this->assertPdfDownload("/sales-invoices/{$id}/pdf?locale=ar");
    }

    public function test_sales_credit_note_pdf_downloads(): void
    {
        $invoiceId = $this->postSalesInvoice();
        $lineId = (int) $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$invoiceId}"))
            ->assertOk()
            ->json('data.lines.0.id');

        $note = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-credit-notes'), [
                'sales_invoice_id' => $invoiceId,
                'type' => 'return',
                'credit_date' => '2026-09-02',
                'reason' => 'Customer return',
                'lines' => [[
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => 1,
                    'restock' => false,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$note}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertPdfDownload("/sales-credit-notes/{$note}/pdf");
    }

    public function test_customer_receipt_pdf_downloads(): void
    {
        $invoiceId = $this->postSalesInvoice();

        $receiptId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), [
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $this->catalog['usd_id'],
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-09-02',
                'amount' => 40,
                'allocations' => [['sales_invoice_id' => $invoiceId, 'amount' => 40]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertPdfDownload("/customer-receipts/{$receiptId}/pdf");
    }

    public function test_goods_receipt_pdf_downloads(): void
    {
        $receiptId = $this->postGoodsReceipt();

        $this->assertPdfDownload("/stock/goods-receipts/{$receiptId}/pdf");
    }

    public function test_purchase_invoice_pdf_downloads(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 2,
                    'unit_price' => 30,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$id}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertPdfDownload("/purchase-invoices/{$id}/pdf");
    }

    public function test_posted_journal_entry_pdf_downloads(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/journal-entries'), [
                'entry_date' => '2026-09-01',
                'description' => 'Printable manual entry',
                'lines' => [
                    ['account_id' => $this->accountId(self::CASH_ACCOUNT), 'debit' => 100],
                    ['account_id' => $this->accountId(self::CAPITAL_ACCOUNT), 'credit' => 100],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.lines')
            ->json('data.id');

        // Draft entries are not printable.
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/journal-entries/{$id}/pdf"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'JOURNAL_ENTRY_PDF_NOT_ALLOWED');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/journal-entries/{$id}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertPdfDownload("/journal-entries/{$id}/pdf");
        $this->assertPdfDownload("/journal-entries/{$id}/pdf?locale=ar");
    }

    /**
     * GET (not getJson) so the body is the raw PDF bytes rather than a JSON error envelope.
     */
    private function assertPdfDownload(string $path): void
    {
        $response = $this->asTenantRequest($this->token)
            ->get($this->tenantUrl($path))
            ->assertOk();

        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    private function stripPrint(string $resourceKey): void
    {
        $this->tenant->run(function () use ($resourceKey): void {
            $permissionId = Permission::query()->where('resource_key', $resourceKey)->value('id');
            RolePermission::query()->where('permission_id', $permissionId)->update(['can_print' => false]);
            app(PermissionService::class)->invalidateCacheForUser($this->tenantUser);
        });
    }

    private function createSalesInvoice(): string
    {
        return (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), [
                'customer_id' => $this->catalog['customer_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 2,
                    'unit_price' => 50,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function postSalesInvoice(): string
    {
        $id = $this->createSalesInvoice();

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        return $id;
    }

    private function postGoodsReceipt(): string
    {
        $id = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/goods-receipts'), [
                'warehouse_id' => $this->catalog['warehouse_id'],
                'supplier_id' => $this->catalog['supplier_id'],
                'received_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 3,
                    'unit_cost' => 20,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/goods-receipts/{$id}/post"))
            ->assertOk();

        return $id;
    }

    private function accountId(string $code): int
    {
        return (int) $this->tenant->run(fn () => Account::query()->where('code', $code)->value('id'));
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

        CompanySetting::singleton()->update([
            'primary_currency_id' => $usd->id,
            'tax_enabled' => true,
            'tax_price_mode' => 'exclusive',
            'allow_negative_stock' => false,
        ]);
        app(CompanySettingService::class)->forgetCache();

        $warehouse = Warehouse::query()->create([
            'name' => 'Print WH',
            'shortcut_name' => 'PWH',
            'type' => WarehouseType::Central,
            'manager_id' => $this->tenantUser->id,
            'is_active' => true,
            'is_default' => true,
            'is_default_sales' => true,
            'is_default_production' => false,
            'is_default_purchase' => true,
            'is_default_storage' => false,
        ]);

        $vat = VatGroup::query()->create([
            'abrv' => 'VAT11',
            'name' => 'VAT 11',
            'percentage' => 11,
            'is_default' => true,
            'is_active' => true,
        ]);

        $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
        $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
        $serviceType = ItemType::query()->where('code', 'SERVICE')->firstOrFail();
        $inventoryType = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();

        $service = $this->makeItem('Print service', 'PDF-SVC', (int) $serviceType->id, (int) $unitGroup->id, (int) $uom->id, (int) $vat->id, false);
        $stock = $this->makeItem('Print stock item', 'PDF-STK', (int) $inventoryType->id, (int) $unitGroup->id, (int) $uom->id, (int) $vat->id, true);

        $customer = Customer::query()->create([
            'name' => 'PDF Customer',
            'type' => 'individual',
            'status' => 'active',
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'PDF Supplier',
            'is_active' => true,
        ]);
        $cash = PaymentMethod::query()->create([
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'requires_reference' => false,
            'is_active' => true,
        ]);

        return [
            'usd_id' => (int) $usd->id,
            'warehouse_id' => (int) $warehouse->id,
            'customer_id' => (string) $customer->id,
            'supplier_id' => (string) $supplier->id,
            'service_item_id' => (string) $service->id,
            'stock_item_id' => (string) $stock->id,
            'cash_method_id' => (int) $cash->id,
        ];
    }

    private function makeItem(
        string $name,
        string $code,
        int $typeId,
        int $unitGroupId,
        int $uomId,
        int $vatId,
        bool $trackInventory,
    ): Item {
        $item = Item::query()->create([
            'name' => $name,
            'item_code' => $code,
            'item_type_id' => $typeId,
            'unit_group_id' => $unitGroupId,
            'base_uom_id' => $uomId,
            'vat_group_id' => $vatId,
            'track_inventory' => $trackInventory,
            'track_lots' => false,
            'allow_sale' => true,
            'allow_purchase' => true,
            'is_active' => true,
        ]);

        ItemUom::query()->create([
            'item_id' => $item->id,
            'uom_id' => $uomId,
            'conversion_factor' => 1,
            'selling_price' => 10,
            'cost_price' => 5,
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
        ]);

        return $item;
    }
}
