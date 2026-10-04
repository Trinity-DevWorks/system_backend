<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Enums\LedgerReferenceType as CustomerLedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPayment;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Supplier\Enums\LedgerReferenceType as SupplierLedgerReferenceType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierLedgerService;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=LedgerStatementApiTest
 */
#[Group('tenant-db')]
class LedgerStatementApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('ledger_statement');
        $this->token = $this->tenantBearerToken();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_customer_statement_carries_opening_running_balance_and_reversal(): void
    {
        $seed = $this->tenant->run(fn (): array => $this->seedCustomerStatement());

        $page = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customers/'.$seed['customer_id'].'/ledger-entries?'.http_build_query([
                'currency_id' => $seed['currency_id'],
                'date_from' => '2026-02-01',
                'date_to' => '2026-02-28',
                'per_page' => 1,
                'page' => 1,
            ])))
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(100.0, (float) $page['summary']['opening_balance'], 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $page['summary']['period_debit'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $page['summary']['period_credit'], 0.001);
        $this->assertEqualsWithDelta(90.0, (float) $page['summary']['closing_balance'], 0.001);
        $this->assertCount(1, $page['data']);
        $this->assertSame('invoice', $page['data'][0]['reference_type']);
        $this->assertFalse($page['data'][0]['reversed']);
        $this->assertSame('SI-000099', $page['data'][0]['document_number']);
        $this->assertEqualsWithDelta(140.0, (float) $page['data'][0]['running_balance'], 0.001);

        $second = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customers/'.$seed['customer_id'].'/ledger-entries?'.http_build_query([
                'currency_id' => $seed['currency_id'],
                'date_from' => '2026-02-01',
                'date_to' => '2026-02-28',
                'per_page' => 1,
                'page' => 2,
            ])))
            ->assertOk()
            ->json('data');

        $this->assertSame('payment', $second['data'][0]['reference_type']);
        $this->assertSame('CR-000099', $second['data'][0]['document_number']);
        $this->assertFalse($second['data'][0]['reversed']);
        $this->assertEqualsWithDelta(130.0, (float) $second['data'][0]['running_balance'], 0.001);

        $third = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customers/'.$seed['customer_id'].'/ledger-entries?'.http_build_query([
                'currency_id' => $seed['currency_id'],
                'date_from' => '2026-02-01',
                'date_to' => '2026-02-28',
                'per_page' => 1,
                'page' => 3,
            ])))
            ->assertOk()
            ->json('data');

        $this->assertSame('invoice', $third['data'][0]['reference_type']);
        $this->assertTrue($third['data'][0]['reversed']);
        $this->assertSame('SI-000099', $third['data'][0]['document_number']);
        $this->assertEqualsWithDelta(90.0, (float) $third['data'][0]['running_balance'], 0.001);

        $empty = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customers/'.$seed['customer_id'].'/ledger-entries?'.http_build_query([
                'currency_id' => $seed['currency_id'],
                'date_from' => '2026-03-01',
                'date_to' => '2026-03-31',
            ])))
            ->assertOk()
            ->json('data');

        $this->assertSame([], $empty['data']);
        $this->assertEqualsWithDelta(90.0, (float) $empty['summary']['opening_balance'], 0.001);
        $this->assertEqualsWithDelta(90.0, (float) $empty['summary']['closing_balance'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $empty['summary']['period_debit'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $empty['summary']['period_credit'], 0.001);

        $openingRow = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customers/'.$seed['customer_id'].'/ledger-entries?'.http_build_query([
                'currency_id' => $seed['currency_id'],
                'per_page' => 1,
                'page' => 1,
            ])))
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame('opening_balance', $openingRow['reference_type']);
        $this->assertNull($openingRow['document_number']);
        $this->assertFalse($openingRow['reversed']);
        $this->assertEqualsWithDelta(100.0, (float) $openingRow['running_balance'], 0.001);
    }

    public function test_supplier_statement_balance_is_credit_minus_debit(): void
    {
        $seed = $this->tenant->run(fn (): array => $this->seedSupplierStatement());

        $page = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/suppliers/'.$seed['supplier_id'].'/ledger-entries?'.http_build_query([
                'currency_id' => $seed['currency_id'],
                'date_from' => '2026-02-01',
                'date_to' => '2026-02-28',
            ])))
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(80.0, (float) $page['summary']['opening_balance'], 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $page['summary']['period_debit'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $page['summary']['period_credit'], 0.001);
        $this->assertEqualsWithDelta(95.0, (float) $page['summary']['closing_balance'], 0.001);
        $this->assertCount(2, $page['data']);
        $this->assertSame('purchase_invoice', $page['data'][0]['reference_type']);
        $this->assertSame('PI-000099', $page['data'][0]['document_number']);
        $this->assertFalse($page['data'][0]['reversed']);
        $this->assertEqualsWithDelta(100.0, (float) $page['data'][0]['running_balance'], 0.001);
        $this->assertSame('payment', $page['data'][1]['reference_type']);
        $this->assertSame('SP-000099', $page['data'][1]['document_number']);
        $this->assertEqualsWithDelta(95.0, (float) $page['data'][1]['running_balance'], 0.001);
    }

    public function test_customer_statement_without_currency_includes_every_currency(): void
    {
        $seed = $this->tenant->run(function (): array {
            $usd = $this->makeCurrency();
            $eur = Currency::query()->create([
                'name' => 'Euro',
                'code' => 'EUR',
                'iso_code' => 'EUR',
                'symbol' => '€',
                'is_active' => true,
            ]);
            $customer = Customer::query()->create([
                'name' => 'Multi Currency Customer',
                'type' => 'individual',
                'status' => 'active',
            ]);
            $ledger = app(CustomerLedgerService::class);
            $ledger->postOpeningBalance($customer, (int) $usd->id, '100', '2026-01-01');
            $ledger->postOpeningBalance($customer, (int) $eur->id, '20', '2026-01-02');
            $ledger->postEntry(
                $customer,
                (int) $usd->id,
                '40',
                '0',
                CustomerLedgerReferenceType::Invoice,
                (string) Str::uuid(),
                '2026-02-01',
            );

            return [
                'customer_id' => (string) $customer->id,
                'usd_id' => (int) $usd->id,
                'eur_id' => (int) $eur->id,
            ];
        });

        $page = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customers/'.$seed['customer_id'].'/ledger-entries'))
            ->assertOk()
            ->json('data');

        $this->assertNull($page['summary']);
        $this->assertCount(2, $page['summaries']);
        $this->assertSame($seed['usd_id'], $page['summaries'][0]['currency_id']);
        $this->assertSame('USD', $page['summaries'][0]['currency_code']);
        $this->assertSame('$', $page['summaries'][0]['currency_symbol']);
        $this->assertEqualsWithDelta(140.0, (float) $page['summaries'][0]['closing_balance'], 0.001);
        $this->assertSame($seed['eur_id'], $page['summaries'][1]['currency_id']);
        $this->assertSame('EUR', $page['summaries'][1]['currency_code']);
        $this->assertSame('€', $page['summaries'][1]['currency_symbol']);
        $this->assertEqualsWithDelta(20.0, (float) $page['summaries'][1]['closing_balance'], 0.001);

        $this->assertCount(3, $page['data']);
        $this->assertSame('USD', $page['data'][0]['currency_code']);
        $this->assertSame('$', $page['data'][0]['currency_symbol']);
        $this->assertEqualsWithDelta(100.0, (float) $page['data'][0]['running_balance'], 0.001);
        $this->assertSame('EUR', $page['data'][1]['currency_code']);
        $this->assertSame('€', $page['data'][1]['currency_symbol']);
        $this->assertEqualsWithDelta(20.0, (float) $page['data'][1]['running_balance'], 0.001);
        $this->assertSame('USD', $page['data'][2]['currency_code']);
        $this->assertEqualsWithDelta(140.0, (float) $page['data'][2]['running_balance'], 0.001);
    }

    /**
     * @return array{customer_id: string, currency_id: int}
     */
    private function seedCustomerStatement(): array
    {
        $currency = $this->makeCurrency();
        $customer = Customer::query()->create([
            'name' => 'Statement Customer',
            'type' => 'individual',
            'status' => 'active',
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Statement WH',
            'shortcut_name' => 'STW',
            'type' => WarehouseType::Central,
            'manager_id' => $this->tenantUser->id,
            'is_active' => true,
        ]);
        $method = PaymentMethod::query()->create([
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'requires_reference' => false,
            'is_active' => true,
        ]);
        $invoice = SalesInvoice::query()->create([
            'invoice_number' => 'SI-000099',
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency_id' => $currency->id,
            'status' => 'posted',
            'invoice_date' => '2026-02-01',
            'due_on' => '2026-02-10',
            'grand_total' => 40,
        ]);
        $receipt = CustomerReceipt::query()->create([
            'receipt_number' => 'CR-000099',
            'customer_id' => $customer->id,
            'currency_id' => $currency->id,
            'payment_method_id' => $method->id,
            'payment_date' => '2026-02-02',
            'amount' => 10,
            'status' => 'posted',
        ]);

        $ledger = app(CustomerLedgerService::class);
        $ledger->postOpeningBalance($customer, (int) $currency->id, '100', '2026-01-01');
        $ledger->postEntry($customer, (int) $currency->id, '40', '0', CustomerLedgerReferenceType::Invoice, (string) $invoice->id, '2026-02-01');
        $ledger->postEntry($customer, (int) $currency->id, '0', '10', CustomerLedgerReferenceType::Payment, (string) $receipt->id, '2026-02-02');
        $ledger->postEntry($customer, (int) $currency->id, '0', '40', CustomerLedgerReferenceType::Invoice, (string) $invoice->id, '2026-02-03');

        return [
            'customer_id' => (string) $customer->id,
            'currency_id' => (int) $currency->id,
        ];
    }

    /**
     * @return array{supplier_id: string, currency_id: int}
     */
    private function seedSupplierStatement(): array
    {
        $currency = $this->makeCurrency();
        $supplier = Supplier::query()->create([
            'name' => 'Statement Supplier',
            'supplier_code' => 'SUP-STMT',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Purchase WH',
            'shortcut_name' => 'PWH',
            'type' => WarehouseType::Central,
            'manager_id' => $this->tenantUser->id,
            'is_active' => true,
        ]);
        $method = PaymentMethod::query()->create([
            'code' => 'CASH2',
            'name' => 'Cash',
            'type' => 'cash',
            'requires_reference' => false,
            'is_active' => true,
        ]);
        $invoice = PurchaseInvoice::query()->create([
            'invoice_number' => 'PI-000099',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'currency_id' => $currency->id,
            'status' => 'posted',
            'invoice_date' => '2026-02-01',
            'due_on' => '2026-02-10',
            'grand_total' => 20,
        ]);
        $payment = SupplierPayment::query()->create([
            'payment_number' => 'SP-000099',
            'supplier_id' => $supplier->id,
            'currency_id' => $currency->id,
            'payment_method_id' => $method->id,
            'payment_date' => '2026-02-02',
            'amount' => 5,
            'status' => 'posted',
        ]);

        $ledger = app(SupplierLedgerService::class);
        $ledger->postOpeningBalance($supplier, (int) $currency->id, '80', '2026-01-01');
        $ledger->postEntry($supplier, (int) $currency->id, '0', '20', SupplierLedgerReferenceType::PurchaseInvoice, (string) $invoice->id, '2026-02-01');
        $ledger->postEntry($supplier, (int) $currency->id, '5', '0', SupplierLedgerReferenceType::Payment, (string) $payment->id, '2026-02-02');

        return [
            'supplier_id' => (string) $supplier->id,
            'currency_id' => (int) $currency->id,
        ];
    }

    private function makeCurrency(): Currency
    {
        $currency = Currency::query()->create([
            'name' => 'US Dollar',
            'code' => 'USD',
            'iso_code' => 'USD',
            'symbol' => '$',
            'is_active' => true,
        ]);
        CompanySetting::singleton()->update(['primary_currency_id' => $currency->id]);
        app(CompanySettingService::class)->forgetCache();

        return $currency;
    }
}
