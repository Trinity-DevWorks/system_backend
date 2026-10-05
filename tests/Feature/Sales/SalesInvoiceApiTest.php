<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Models\CurrencyPairRate;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerLedgerEntry;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\InvoiceProof\CanonicalInvoiceSchema;
use App\Modules\InvoiceProof\Contracts\CompanySafeOwnerLookup;
use App\Modules\InvoiceProof\Contracts\InvoiceRegistryGateway;
use App\Modules\InvoiceProof\DTOs\InvoiceAttestationRecord;
use App\Modules\InvoiceProof\DTOs\InvoiceChainReceiptData;
use App\Modules\InvoiceProof\DTOs\InvoiceOnChainRecord;
use App\Modules\InvoiceProof\DTOs\WalletInspectionData;
use App\Modules\InvoiceProof\Enums\InvoiceChainCheckStatus;
use App\Modules\InvoiceProof\Enums\InvoiceOnChainStatus;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierChainStatus;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use App\Modules\InvoiceProof\Enums\WalletType;
use App\Modules\InvoiceProof\Exceptions\CompanySignerWalletException;
use App\Modules\InvoiceProof\Jobs\RegisterSalesInvoiceOnChainJob;
use App\Modules\InvoiceProof\Jobs\RevokeSalesInvoiceOnChainJob;
use App\Modules\InvoiceProof\Jobs\SyncInvoiceVerifierOnChainJob;
use App\Modules\InvoiceProof\Models\InvoiceChainCheck;
use App\Modules\InvoiceProof\Models\InvoiceChainRegistration;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Models\InvoiceVerifier;
use App\Modules\InvoiceProof\Services\InvoiceChainRegistrationService;
use App\Modules\InvoiceProof\Services\InvoiceSnapshotService;
use App\Modules\InvoiceProof\Services\InvoiceVerifierService;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceHasher;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceMerkle;
use App\Modules\InvoiceProof\Support\CompanySafeSignerGuard;
use App\Modules\InvoiceProof\Support\EmptyCompanySafeOwnerLookup;
use App\Modules\InvoiceProof\Support\EthereumPersonalSign;
use App\Modules\InvoiceProof\Support\InvoiceProofBytes;
use App\Modules\InvoiceProof\Support\ProofPortalLink;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Models\RolePermission;
use App\Modules\Rbac\RbacResourceCatalog;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=SalesInvoiceApiTest
 */
#[Group('tenant-db')]
class SalesInvoiceApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('sales_invoice_v1');
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

    public function test_create_syncs_tax_snapshots_and_assigns_number(): void
    {
        $payload = $this->baseInvoicePayload([
            'lines' => [[
                'item_id' => $this->catalog['service_item_id'],
                'quantity' => 2,
                'unit_price' => 10,
                'discount_percent' => 10,
            ]],
        ]);

        $create = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $payload)
            ->assertCreated()
            ->assertJsonPath('success', true);

        $id = $create->json('data.id');
        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression('/^SI-\d+$/', (string) $create->json('data.invoice_number'));
        $this->assertEqualsWithDelta(18.0, (float) $create->json('data.lines.0.line_subtotal'), 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $create->json('data.lines.0.tax_rate'), 0.001);
        $this->assertEqualsWithDelta(1.8, (float) $create->json('data.lines.0.tax_amount'), 0.001);
        $this->assertEqualsWithDelta(19.8, (float) $create->json('data.lines.0.line_total'), 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $create->json('data.subtotal'), 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $create->json('data.discount_total'), 0.001);
        $this->assertEqualsWithDelta(1.8, (float) $create->json('data.tax_total'), 0.001);
        $this->assertEqualsWithDelta(19.8, (float) $create->json('data.grand_total'), 0.001);
        $this->assertEqualsWithDelta(19.8, (float) $create->json('data.net_to_pay'), 0.001);
    }

    public function test_exchange_rate_is_one_for_primary_and_pair_snapshot_otherwise(): void
    {
        $primary = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload())
            ->assertCreated();

        $this->assertEqualsWithDelta(1.0, (float) $primary->json('data.exchange_rate'), 0.000001);

        $fx = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'currency_id' => $this->catalog['eur_id'],
            ]))
            ->assertCreated();

        // Only EUR→USD = 3.5 exists, so 1 USD = 1/3.5 EUR.
        $this->assertEqualsWithDelta(1 / 3.5, (float) $fx->json('data.exchange_rate'), 0.000000000001);

        $override = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'currency_id' => $this->catalog['eur_id'],
                'exchange_rate' => 4.25,
            ]))
            ->assertCreated();

        $this->assertEqualsWithDelta(4.25, (float) $override->json('data.exchange_rate'), 0.000001);
    }

    public function test_post_writes_customer_ledger_debit_with_uuid_reference(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->tenant->run(function () use ($id): void {
            $invoice = SalesInvoice::query()->findOrFail($id);
            $entry = CustomerLedgerEntry::query()
                ->where('customer_id', $invoice->customer_id)
                ->where('reference_type', LedgerReferenceType::Invoice)
                ->where('reference_id', $id)
                ->first();

            $this->assertNotNull($entry);
            $this->assertIsString($entry->reference_id);
            $this->assertSame($id, $entry->reference_id);
            $this->assertEqualsWithDelta(55.0, (float) $entry->debit, 0.0001);
            $this->assertEqualsWithDelta(0.0, (float) $entry->credit, 0.0001);
        });
    }

    public function test_post_creates_an_immutable_invoice_snapshot(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk();

        $this->tenant->run(function () use ($id): void {
            $invoice = SalesInvoice::query()->with('snapshot')->findOrFail($id);
            $snapshot = $invoice->snapshot;

            $this->assertInstanceOf(InvoiceSnapshot::class, $snapshot);
            $this->assertSame('sales', $snapshot->invoice_type->value);
            $this->assertSame($id, $snapshot->invoice_id);
            $this->assertSame(CanonicalInvoiceSchema::VERSION, $snapshot->schema_version);
            $this->assertSame(64, strlen($snapshot->content_hash));
            $this->assertSame(64, strlen((string) $snapshot->disclosure_secret));
            $this->assertArrayNotHasKey('disclosure_secret', $snapshot->toArray());
            $this->assertSame(
                CanonicalInvoiceHasher::hash($snapshot->canonical_json, (string) $snapshot->disclosure_secret),
                $snapshot->content_hash,
            );

            $payload = json_decode($snapshot->canonical_json, true);
            $this->assertIsArray($payload);
            $this->assertSame($snapshot->id, $payload['proof_id']);
            $this->assertSame('sales', $payload['invoice_type']);

            try {
                app(InvoiceSnapshotService::class)->captureSalesInvoice($invoice);
                $this->fail('Expected a duplicate snapshot to be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
                $this->assertSame('INVOICE_SNAPSHOT_ALREADY_EXISTS', $exception->getHeaders()['X-Error-Code'] ?? null);
            }
        });
    }

    public function test_verify_returns_not_registered_for_a_draft_invoice(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/verify"))
            ->assertOk()
            ->assertJsonPath('data.status', 'not_registered')
            ->assertJsonPath('data.snapshot_intact', null)
            ->assertJsonPath('data.live_invoice_matches', null)
            ->assertJsonPath('data.chain_matches', null);
    }

    public function test_verify_returns_verified_after_post(): void
    {
        $id = $this->postServiceInvoice();

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/verify"))
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.snapshot_intact', true)
            ->assertJsonPath('data.live_invoice_matches', true)
            ->assertJsonPath('data.chain_matches', null);
    }

    public function test_posted_invoice_detail_shows_sealed_names_not_later_master_data(): void
    {
        $id = $this->postServiceInvoice();

        $this->tenant->run(function (): void {
            Item::query()->whereKey($this->catalog['service_item_id'])->update(['name' => 'Renamed Item']);
            Customer::query()->whereKey($this->catalog['customer_id'])->update([
                'name' => 'Renamed Customer',
                'wallet_address' => '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
                'wallet_type' => 'wallet',
            ]);
        });

        $data = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}"))
            ->assertOk()
            ->json('data');

        $this->assertIsArray($data);
        $this->assertSame('Invoice Customer', $data['customer']['name'] ?? null);
        $this->assertSame('0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc', $data['customer']['wallet_address'] ?? null);
        $this->assertSame('wallet', $data['customer']['wallet_type'] ?? null);
        $this->assertSame('Service item', $data['lines'][0]['item']['name'] ?? null);
        $this->assertArrayHasKey('paid_total', $data);
        $this->assertArrayHasKey('net_to_pay', $data);
    }

    public function test_post_queues_chain_registration_when_blockchain_is_configured(): void
    {
        Queue::fake();
        config([
            'blockchain.enabled' => true,
            'blockchain.rpc_url' => 'http://127.0.0.1:8545',
            'blockchain.contract_address' => '0x5FbDB2315678afecb367f032d93F642f64180aa3',
            'blockchain.registrar_address' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
        ]);

        $id = $this->postServiceInvoice();

        Queue::assertPushed(RegisterSalesInvoiceOnChainJob::class);

        $this->tenant->run(function () use ($id): void {
            $this->assertTrue(
                InvoiceChainRegistration::query()->where('invoice_id', $id)->where('status', 'pending')->exists(),
            );
        });
    }

    public function test_post_skips_snapshot_and_chain_when_invoice_proofs_disabled(): void
    {
        Queue::fake();
        $this->setInvoiceProofsEnabled(false);
        config([
            'blockchain.enabled' => true,
            'blockchain.rpc_url' => 'http://127.0.0.1:8545',
            'blockchain.contract_address' => '0x5FbDB2315678afecb367f032d93F642f64180aa3',
            'blockchain.registrar_address' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
        ]);

        $id = $this->postServiceInvoice();

        Queue::assertNotPushed(RegisterSalesInvoiceOnChainJob::class);

        $this->tenant->run(function () use ($id): void {
            $this->assertFalse(InvoiceSnapshot::query()->where('invoice_id', $id)->exists());
            $this->assertFalse(InvoiceChainRegistration::query()->where('invoice_id', $id)->exists());
        });
    }

    public function test_verify_forbidden_when_invoice_proofs_disabled(): void
    {
        $this->setInvoiceProofsEnabled(false);
        $id = $this->postServiceInvoice();

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/verify"))
            ->assertForbidden()
            ->assertJsonPath('code', 'INVOICE_PROOFS_DISABLED');
    }

    public function test_approve_as_company_forbidden_when_invoice_proofs_disabled(): void
    {
        $this->setInvoiceProofsEnabled(false);
        $id = $this->postServiceInvoice();

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/approve-as-company"))
            ->assertForbidden()
            ->assertJsonPath('code', 'INVOICE_PROOFS_DISABLED');
    }

    public function test_permissions_catalog_includes_invoice_proofs_view_and_edit(): void
    {
        $rows = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/permissions'))
            ->assertOk()
            ->json('data');

        $this->assertIsArray($rows);
        $proofs = collect($rows)->firstWhere('resource_key', 'invoice_proofs');
        $this->assertIsArray($proofs);
        $this->assertSame(['view', 'edit'], $proofs['actions'] ?? null);
    }

    public function test_invoice_proofs_permission_cannot_be_granted_when_proofs_disabled(): void
    {
        $this->setInvoiceProofsEnabled(false);

        $roleId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/roles'), [
                'name' => 'Invoice Clerk',
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('data.id');

        $catalog = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/permissions'))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($catalog);

        $payload = [];
        foreach ($catalog as $row) {
            $payload[] = [
                'permission_id' => $row['id'],
                'can_view' => ($row['resource_key'] ?? null) === 'invoice_proofs',
                'can_add' => false,
                'can_edit' => ($row['resource_key'] ?? null) === 'invoice_proofs',
                'can_delete' => false,
                'can_import' => false,
                'can_export' => false,
                'can_reverse' => false,
            ];
        }

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/roles/{$roleId}/permissions"), [
                'permissions' => $payload,
            ])
            ->assertOk();

        $saved = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/roles/{$roleId}/permissions"))
            ->assertOk()
            ->json('data.permissions');

        $this->assertIsArray($saved);
        $proofs = collect($saved)->firstWhere('resource_key', 'invoice_proofs');
        $this->assertIsArray($proofs);
        $this->assertFalse((bool) ($proofs['can_view'] ?? true));
        $this->assertFalse((bool) ($proofs['can_edit'] ?? true));
    }

    public function test_buyer_link_requires_invoice_proofs_view_and_verify_allows_edit(): void
    {
        $id = $this->postServiceInvoice();
        $clerkToken = $this->clerkTokenWithoutInvoiceProofsView();

        Auth::forgetGuards();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/verify"))
            ->assertOk();

        Auth::forgetGuards();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/buyer-portal-link"))
            ->assertForbidden();
    }

    public function test_verify_forbidden_without_invoice_proofs_view_or_edit(): void
    {
        $id = $this->postServiceInvoice();
        $clerkToken = $this->clerkTokenWithoutInvoiceProofsView(false);

        Auth::forgetGuards();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/verify"))
            ->assertForbidden();
    }

    public function test_proof_fields_lists_sealed_leaves_without_the_secret(): void
    {
        $id = $this->postServiceInvoice();

        $response = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/proof-fields"))
            ->assertOk()
            ->assertJsonPath('data.schema_version', CanonicalInvoiceSchema::VERSION);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $paths = array_column($data['fields'], 'path');
        $this->assertSame('schema_version', $paths[0]);
        $this->assertContains('buyer.name', $paths);
        $this->assertContains('lines.0.item_code', $paths);
        $this->assertContains('grand_total', $paths);
        $this->assertSame(count($paths), $data['leaf_count']);
        $this->assertStringNotContainsString('disclosure_secret', $response->getContent());
    }

    public function test_proof_disclosure_returns_only_requested_fields_with_verifiable_proofs(): void
    {
        $id = $this->postServiceInvoice();

        $response = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/proof-disclosure"), [
                'fields' => ['grand_total', 'buyer.name'],
            ])
            ->assertOk()
            ->assertJsonPath('data.format', 'invoice-proof-disclosure')
            ->assertJsonCount(2, 'data.fields');

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertSame(['buyer.name', 'grand_total'], array_column($data['fields'], 'path'));
        foreach ($data['fields'] as $field) {
            $this->assertTrue(CanonicalInvoiceMerkle::verify(
                $field['path'],
                $field['value'],
                $field['salt'],
                $field['proof'],
                $data['content_hash'],
            ), $field['path']);
        }
        $this->assertStringNotContainsString('Service item', $response->getContent());
        $this->assertStringNotContainsString('disclosure_secret', $response->getContent());
    }

    public function test_proof_disclosure_rejects_unknown_fields(): void
    {
        $id = $this->postServiceInvoice();

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/proof-disclosure"), [
                'fields' => ['grand_total', 'buyer.bank_account'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVOICE_PROOF_FIELD_UNKNOWN');
    }

    public function test_proof_disclosure_rejects_draft_invoice(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/proof-fields"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_NOT_POSTED');
    }

    public function test_proof_disclosure_blocked_when_snapshot_was_tampered(): void
    {
        $id = $this->postServiceInvoice();

        $this->tenant->run(function () use ($id): void {
            $snapshot = InvoiceSnapshot::query()->where('invoice_id', $id)->firstOrFail();
            DB::table('invoice_snapshots')->where('id', $snapshot->id)->update([
                'canonical_json' => str_replace('50.0000', '99.0000', $snapshot->canonical_json),
            ]);
        });

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/proof-disclosure"), [
                'fields' => ['grand_total'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVOICE_PROOF_TAMPERED');
    }

    public function test_proof_disclosure_forbidden_when_invoice_proofs_disabled(): void
    {
        $id = $this->postServiceInvoice();
        $this->setInvoiceProofsEnabled(false);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/proof-fields"))
            ->assertForbidden()
            ->assertJsonPath('code', 'INVOICE_PROOFS_DISABLED');
    }

    public function test_proof_disclosure_requires_invoice_proofs_view(): void
    {
        $id = $this->postServiceInvoice();
        $clerkToken = $this->clerkTokenWithoutInvoiceProofsView();

        Auth::forgetGuards();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/proof-fields"))
            ->assertForbidden();

        Auth::forgetGuards();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/proof-disclosure"), ['fields' => ['grand_total']])
            ->assertForbidden();
    }

    public function test_invoice_verifier_create_is_listed_and_queues_chain_sync(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $this->app->instance(CompanySafeOwnerLookup::class, new EmptyCompanySafeOwnerLookup);

        $verifierId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/invoice-verifiers'), [
                'name' => 'Bank A',
                'role' => 'financier',
                'wallet_address' => '0x9965507D1a55bcC2695C58ba16FB37d819B0A4dc',
                'wallet_type' => 'wallet',
                'email' => 'proofs@bank-a.test',
                'phone' => '+964 770 000 0000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Bank A')
            ->assertJsonPath('data.role', 'financier')
            ->assertJsonPath('data.wallet_address', '0x9965507d1a55bcc2695c58ba16fb37d819b0a4dc')
            ->assertJsonPath('data.email', 'proofs@bank-a.test')
            ->assertJsonPath('data.phone', '+964 770 000 0000')
            ->assertJsonPath('data.chain_status', 'pending')
            ->json('data.id');

        Queue::assertPushed(SyncInvoiceVerifierOnChainJob::class);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/invoice-verifiers'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bank A');

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/invoice-verifiers/{$verifierId}"))
            ->assertOk()
            ->assertJsonPath('data.id', $verifierId)
            ->assertJsonPath('data.email', 'proofs@bank-a.test');
    }

    public function test_invoice_verifier_rejects_company_safe_wallet(): void
    {
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/invoice-verifiers'), [
                'name' => 'Self',
                'role' => 'auditor',
                'wallet_address' => '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
                'wallet_type' => 'wallet',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['wallet_address']);
    }

    public function test_invoice_verifier_sync_lists_wallet_for_company_safe(): void
    {
        $this->enableBlockchainConfig();
        $verifierId = $this->tenant->run(fn (): string => (string) InvoiceVerifier::query()->create([
            'name' => 'Bank A',
            'role' => InvoiceVerifierRole::Financier,
            'wallet_address' => '0x9965507d1a55bcc2695c58ba16fb37d819b0a4dc',
            'chain_status' => InvoiceVerifierChainStatus::Pending,
        ])->id);

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->expects($this->once())
            ->method('setVerifier')
            ->with(
                '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
                '0x9965507d1a55bcc2695c58ba16fb37d819b0a4dc',
                3,
            )
            ->willReturn(new InvoiceChainReceiptData(
                txHash: '0xabc',
                blockNumber: 1,
                contractAddress: '0x5fbdb2315678afecb367f032d93f642f64180aa3',
            ));
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->tenant->run(function () use ($verifierId): void {
            app(InvoiceVerifierService::class)->syncOnChain($verifierId);

            $verifier = InvoiceVerifier::query()->findOrFail($verifierId);
            $this->assertSame(InvoiceVerifierChainStatus::Active, $verifier->chain_status);
            $this->assertSame('0x70997970c51812dc3a010c7d01b50e0d17dc79c8', $verifier->chain_company_wallet);
            $this->assertSame('0xabc', $verifier->chain_tx_hash);
        });
    }

    public function test_invoice_verifier_delete_revokes_on_chain_then_removes_row(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $verifierId = $this->tenant->run(fn (): string => (string) InvoiceVerifier::query()->create([
            'name' => 'Audit Co',
            'role' => InvoiceVerifierRole::Auditor,
            'wallet_address' => '0x90f79bf6eb2c4f870365e785982e1f101e93b906',
            'chain_status' => InvoiceVerifierChainStatus::Active,
            'chain_company_wallet' => '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
        ])->id);

        $this->asTenantRequest($this->token)
            ->deleteJson($this->tenantUrl("/invoice-verifiers/{$verifierId}"))
            ->assertOk();

        Queue::assertPushed(SyncInvoiceVerifierOnChainJob::class);

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->expects($this->once())
            ->method('setVerifier')
            ->with(
                '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
                '0x90f79bf6eb2c4f870365e785982e1f101e93b906',
                0,
            )
            ->willReturn(new InvoiceChainReceiptData(
                txHash: '0xdef',
                blockNumber: 2,
                contractAddress: '0x5fbdb2315678afecb367f032d93f642f64180aa3',
            ));
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->tenant->run(function () use ($verifierId): void {
            $this->assertSame(
                InvoiceVerifierChainStatus::Removing,
                InvoiceVerifier::query()->findOrFail($verifierId)->chain_status,
            );

            app(InvoiceVerifierService::class)->syncOnChain($verifierId);

            $this->assertNull(InvoiceVerifier::query()->find($verifierId));
        });
    }

    public function test_invoice_verifier_changes_require_invoice_proofs_edit(): void
    {
        $clerkToken = $this->clerkTokenWithoutInvoiceProofsEdit();

        Auth::forgetGuards();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->postJson($this->tenantUrl('/invoice-verifiers'), [
                'name' => 'Bank A',
                'role' => 'financier',
                'wallet_address' => '0x9965507D1a55bcC2695C58ba16FB37d819B0A4dc',
                'wallet_type' => 'wallet',
            ])
            ->assertForbidden();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->getJson($this->tenantUrl('/invoice-verifiers'))
            ->assertOk();
    }

    public function test_verify_names_attestations_from_company_verifiers(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $id = $this->postServiceInvoice();

        $proofId = null;
        $hash = null;
        $this->tenant->run(function () use ($id, &$proofId, &$hash): void {
            $snapshot = InvoiceSnapshot::query()->where('invoice_id', $id)->firstOrFail();
            $proofId = (string) $snapshot->id;
            $hash = $snapshot->content_hash;
            InvoiceVerifier::query()->create([
                'name' => 'Bank A',
                'role' => InvoiceVerifierRole::Financier,
                'wallet_address' => '0x9965507d1a55bcc2695c58ba16fb37d819b0a4dc',
                'chain_status' => InvoiceVerifierChainStatus::Active,
            ]);
        });

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->with($proofId)->willReturn(new InvoiceOnChainRecord(
            contentHash: $hash,
            supplierAddress: '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            buyerAddress: '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
            supplierApproved: true,
            buyerApproved: true,
            status: InvoiceOnChainStatus::FullyApproved,
        ));
        $gateway->method('attestationsOf')->with($proofId)->willReturn([
            new InvoiceAttestationRecord(
                '0x9965507d1a55bcc2695c58ba16fb37d819b0a4dc',
                InvoiceVerifierRole::Financier,
                null,
                1700000000,
            ),
        ]);
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/verify"))
            ->assertOk()
            ->assertJsonPath('data.attestations.0.verifier_name', 'Bank A')
            ->assertJsonPath('data.attestations.0.role', 'financier')
            ->assertJsonPath('data.financed_by', '0x9965507d1a55bcc2695c58ba16fb37d819b0a4dc');
    }

    public function test_chain_check_reports_consistent_when_chain_matches(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $this->tenant->run(fn () => CompanyProfile::singleton()->update([
            'wallet_address' => '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
        ]));
        [$proofId, $hash] = $this->confirmedProof($this->postServiceInvoice());

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->with($proofId)->willReturn($this->chainRecord($hash));
        $gateway->method('latestBlockNumber')->willReturn(12);
        $gateway->expects($this->once())
            ->method('registeredBySupplier')
            ->with('0x70997970c51812dc3a010c7d01b50e0d17dc79c8', 0, 12)
            ->willReturn([[
                'proof_id' => InvoiceProofBytes::proofIdToBytes32($proofId),
                'content_hash' => $hash,
                'block_number' => 5,
            ]]);
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/invoice-proofs/chain-check'))
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.check.status', 'consistent')
            ->assertJsonPath('data.check.checked_count', 1)
            ->assertJsonPath('data.check.issue_count', 0)
            ->assertJsonPath('data.check.scanned_from_block', 0)
            ->assertJsonPath('data.check.scanned_to_block', 12)
            ->assertJsonCount(0, 'data.check.issues');

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/invoice-proofs/chain-check'))
            ->assertOk()
            ->assertJsonPath('data.check.status', 'consistent');
    }

    public function test_chain_check_command_flags_altered_mismatched_and_unknown_proofs(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $this->tenant->run(fn () => CompanyProfile::singleton()->update([
            'wallet_address' => '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
        ]));
        [$alteredId, $alteredHash] = $this->confirmedProof($this->postServiceInvoice());
        [$mismatchId] = $this->confirmedProof($this->postServiceInvoice());
        $unknownProof = InvoiceProofBytes::proofIdToBytes32('0b0e8d3c-1b2a-4c5d-8e9f-a0b1c2d3e4f5');
        $otherHash = '0x'.str_repeat('ab', 32);

        $this->tenant->run(function () use ($alteredId): void {
            $snapshot = InvoiceSnapshot::query()->findOrFail($alteredId);
            $canonical = json_decode((string) $snapshot->canonical_json, true);
            $canonical['invoice_number'] = 'TAMPERED';
            InvoiceSnapshot::query()->whereKey($alteredId)->toBase()->update([
                'canonical_json' => json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        });

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->willReturnCallback(
            fn (string $id): InvoiceOnChainRecord => $this->chainRecord($id === $alteredId ? $alteredHash : $otherHash),
        );
        $gateway->method('latestBlockNumber')->willReturn(3);
        $gateway->method('registeredBySupplier')->willReturn([[
            'proof_id' => $unknownProof,
            'content_hash' => $otherHash,
            'block_number' => 2,
        ]]);
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->artisan('invoice-proofs:check-chain', ['--tenant' => $this->tenant->id])->assertSuccessful();

        $this->tenant->run(function () use ($alteredId, $mismatchId, $unknownProof): void {
            $check = InvoiceChainCheck::query()->with('issues')->sole();
            $this->assertSame(InvoiceChainCheckStatus::Issues, $check->status);
            $this->assertSame(2, $check->checked_count);

            $kinds = $check->issues->mapWithKeys(
                fn ($issue): array => [$issue->kind->value => $issue->proof_id ?? $issue->chain_proof_id],
            )->sortKeys()->all();
            $this->assertSame([
                'hash_mismatch' => $mismatchId,
                'snapshot_altered' => $alteredId,
                'unknown_on_chain' => $unknownProof,
            ], $kinds);
        });
    }

    public function test_chain_check_requeues_stuck_registration(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $id = $this->postServiceInvoice();
        $proofId = $this->tenant->run(function () use ($id): string {
            $proofId = (string) InvoiceSnapshot::query()->where('invoice_id', $id)->firstOrFail()->id;
            InvoiceChainRegistration::query()->where('proof_id', $proofId)->toBase()->update([
                'status' => 'failed',
                'last_error' => 'RPC timeout',
                'updated_at' => now()->subHour(),
            ]);

            return $proofId;
        });
        Queue::fake();

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->willReturn(null);
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/invoice-proofs/chain-check'))
            ->assertOk()
            ->assertJsonPath('data.check.status', 'issues')
            ->assertJsonPath('data.check.requeued_count', 1)
            ->assertJsonPath('data.check.issues.0.kind', 'registration_stuck')
            ->assertJsonPath('data.check.issues.0.proof_id', $proofId)
            ->assertJsonPath('data.check.issues.0.actual', 'failed RPC timeout');

        Queue::assertPushed(RegisterSalesInvoiceOnChainJob::class, 1);
    }

    public function test_reverse_queues_chain_revocation_and_job_revokes_proof(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $id = $this->postServiceInvoice();
        [$proofId, $hash] = $this->confirmedProof($id);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        Queue::assertPushed(RevokeSalesInvoiceOnChainJob::class, fn (RevokeSalesInvoiceOnChainJob $job): bool => $job->proofId === $proofId);
        $this->tenant->run(function () use ($proofId): void {
            $registration = InvoiceChainRegistration::query()->where('proof_id', $proofId)->sole();
            $this->assertNotNull($registration->revoke_requested_at);
            $this->assertNull($registration->revoked_at);
        });

        $revoked = new InvoiceOnChainRecord(
            contentHash: $hash,
            supplierAddress: '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            buyerAddress: '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
            supplierApproved: false,
            buyerApproved: false,
            status: InvoiceOnChainStatus::Revoked,
            revokedAt: 1700000900,
        );
        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->expects($this->once())->method('revokeInvoice')->with($proofId, null)->willReturn(
            new InvoiceChainReceiptData('0x'.str_repeat('ef', 32), 7, '0x5fbdb2315678afecb367f032d93f642f64180aa3'),
        );
        $gateway->method('invoiceOf')->willReturn($revoked);
        $gateway->method('attestationsOf')->willReturn([]);
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->tenant->run(function () use ($proofId): void {
            $service = app(InvoiceChainRegistrationService::class);
            $service->submitRevocation($proofId);
            $service->submitRevocation($proofId);

            $registration = InvoiceChainRegistration::query()->where('proof_id', $proofId)->sole();
            $this->assertNotNull($registration->revoked_at);
            $this->assertSame('0x'.str_repeat('ef', 32), $registration->revoke_tx_hash);
            $this->assertSame('revoked', $registration->chain_status?->value);
        });

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/verify"))
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.revoked_at', '2023-11-14T22:28:20+00:00')
            ->assertJsonPath('data.can_approve_as_company', false);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.chain_status.status', 'revoked');
    }

    public function test_reverse_of_unregistered_invoice_revokes_after_late_registration(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $id = $this->postServiceInvoice();
        $proofId = $this->tenant->run(fn (): string => (string) InvoiceSnapshot::query()->where('invoice_id', $id)->firstOrFail()->id);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/reverse"))
            ->assertOk();
        Queue::assertNotPushed(RevokeSalesInvoiceOnChainJob::class);

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('registerInvoice')->willReturn(
            new InvoiceChainReceiptData('0x'.str_repeat('aa', 32), 5, '0x5fbdb2315678afecb367f032d93f642f64180aa3'),
        );
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->tenant->run(fn () => app(InvoiceChainRegistrationService::class)->submitProof($proofId));

        Queue::assertPushed(RevokeSalesInvoiceOnChainJob::class, fn (RevokeSalesInvoiceOnChainJob $job): bool => $job->proofId === $proofId);
    }

    public function test_chain_check_requeues_revocation_for_reversed_invoice(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $id = $this->postServiceInvoice();
        [$proofId, $hash] = $this->confirmedProof($id);
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/reverse"))
            ->assertOk();
        $this->tenant->run(fn () => InvoiceChainRegistration::query()->where('proof_id', $proofId)->update([
            'revoke_requested_at' => now()->subHour(),
            'revoke_error' => 'RPC timeout',
        ]));
        Queue::fake();

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->willReturn($this->chainRecord($hash));
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/invoice-proofs/chain-check'))
            ->assertOk()
            ->assertJsonPath('data.check.status', 'issues')
            ->assertJsonPath('data.check.requeued_count', 1)
            ->assertJsonPath('data.check.issues.0.kind', 'not_revoked')
            ->assertJsonPath('data.check.issues.0.expected', 'revoked')
            ->assertJsonPath('data.check.issues.0.actual', 'waiting_company RPC timeout');

        Queue::assertPushed(RevokeSalesInvoiceOnChainJob::class, 1);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.chain_issue.kind', 'not_revoked');

        $this->tenant->run(fn () => InvoiceChainRegistration::query()->where('proof_id', $proofId)->update([
            'revoked_at' => now(),
        ]));

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.chain_issue', null);
    }

    public function test_sales_invoice_responses_include_latest_chain_issue(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $mismatchInvoice = $this->postServiceInvoice();
        $stuckInvoice = $this->postServiceInvoice();
        [$mismatchProof] = $this->confirmedProof($mismatchInvoice);

        $stuckProof = $this->tenant->run(function () use ($stuckInvoice): string {
            $proofId = (string) InvoiceSnapshot::query()->where('invoice_id', $stuckInvoice)->firstOrFail()->id;
            InvoiceChainRegistration::query()->where('proof_id', $proofId)->toBase()->update([
                'updated_at' => now()->subHour(),
            ]);

            return $proofId;
        });

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->willReturnCallback(
            fn (string $id): ?InvoiceOnChainRecord => $id === $mismatchProof ? $this->chainRecord('0x'.str_repeat('cd', 32)) : null,
        );
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/invoice-proofs/chain-check'))
            ->assertOk()
            ->assertJsonPath('data.check.issue_count', 2);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$mismatchInvoice}"))
            ->assertOk()
            ->assertJsonPath('data.chain_issue.kind', 'hash_mismatch');

        $rows = collect($this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/sales-invoices'))
            ->assertOk()
            ->json('data.data'))->keyBy('id');
        $this->assertSame('hash_mismatch', $rows[$mismatchInvoice]['chain_issue']['kind']);
        $this->assertSame('registration_stuck', $rows[$stuckInvoice]['chain_issue']['kind']);

        $this->tenant->run(fn () => InvoiceChainRegistration::query()->where('proof_id', $stuckProof)->update([
            'status' => 'confirmed',
        ]));

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$stuckInvoice}"))
            ->assertOk()
            ->assertJsonPath('data.chain_issue', null);

        $clerkToken = $this->clerkTokenWithoutInvoiceProofsView(false);
        Auth::forgetGuards();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->getJson($this->tenantUrl("/sales-invoices/{$mismatchInvoice}"))
            ->assertOk()
            ->assertJsonPath('data.chain_issue', null);
    }

    public function test_sales_invoice_responses_include_last_read_chain_status(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $sealedInvoice = $this->postServiceInvoice();
        $pendingInvoice = $this->postServiceInvoice();
        [$sealedProof, $hash] = $this->confirmedProof($sealedInvoice);

        $rows = collect($this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/sales-invoices'))
            ->assertOk()
            ->json('data.data'))->keyBy('id');
        $this->assertSame('waiting_company', $rows[$sealedInvoice]['chain_status']['status']);
        $this->assertSame('pending_chain', $rows[$pendingInvoice]['chain_status']['status']);

        $onChain = new InvoiceOnChainRecord(
            contentHash: $hash,
            supplierAddress: '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            buyerAddress: '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
            supplierApproved: true,
            buyerApproved: true,
            status: InvoiceOnChainStatus::FullyApproved,
        );
        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->willReturnCallback(
            function (string $id) use (&$onChain, $sealedProof): ?InvoiceOnChainRecord {
                return $id === $sealedProof ? $onChain : null;
            },
        );
        $gateway->method('attestationsOf')->willReturn([
            new InvoiceAttestationRecord('0x9965507d1a55bcc2695c58ba16fb37d819b0a4dc', InvoiceVerifierRole::Financier, null, 1700000000),
        ]);
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$sealedInvoice}/verify"))
            ->assertOk()
            ->assertJsonPath('data.status', 'fully_approved');

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$sealedInvoice}"))
            ->assertOk()
            ->assertJsonPath('data.chain_status.status', 'fully_approved')
            ->assertJsonPath('data.chain_status.financed', true);

        $onChain = $this->chainRecord($hash);
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/invoice-proofs/chain-check'))
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$sealedInvoice}"))
            ->assertOk()
            ->assertJsonPath('data.chain_status.status', 'waiting_company')
            ->assertJsonPath('data.chain_status.financed', false);

        $this->tenant->run(function () use ($pendingInvoice): void {
            $this->assertNull(InvoiceChainRegistration::query()->where('invoice_id', $pendingInvoice)->sole()->chain_status);
        });

        $clerkToken = $this->clerkTokenWithoutInvoiceProofsView(false);
        Auth::forgetGuards();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->getJson($this->tenantUrl("/sales-invoices/{$sealedInvoice}"))
            ->assertOk()
            ->assertJsonPath('data.chain_status', null);
    }

    public function test_chain_check_run_requires_invoice_proofs_edit(): void
    {
        $clerkToken = $this->clerkTokenWithoutInvoiceProofsEdit();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->postJson($this->tenantUrl('/invoice-proofs/chain-check'))
            ->assertForbidden();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->getJson($this->tenantUrl('/invoice-proofs/chain-check'))
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.check', null);
    }

    public function test_chain_check_forbidden_when_invoice_proofs_disabled(): void
    {
        $this->setInvoiceProofsEnabled(false);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/invoice-proofs/chain-check'))
            ->assertForbidden()
            ->assertJsonPath('code', 'INVOICE_PROOFS_DISABLED');
    }

    public function test_approve_as_company_forbidden_without_invoice_proofs_permission(): void
    {
        $id = $this->postServiceInvoice();
        $clerkToken = $this->clerkTokenWithoutInvoiceProofsEdit();

        // postServiceInvoice() authenticates as Owner on the same app instance.
        // Forget the guard so Sanctum re-reads the clerk Bearer token.
        Auth::forgetGuards();

        $this->flushHeaders()
            ->asTenantRequest($clerkToken)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/approve-as-company"))
            ->assertForbidden();
    }

    public function test_approve_as_company_rejected_for_draft_invoice(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/approve-as-company"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_NOT_POSTED');
    }

    public function test_submit_proof_registers_stored_company_and_customer_wallets(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();

        $companyWallet = '0x90F79bf6EB2c4f870365E785982E1f101E93b906';
        $customerWallet = '0x15d34AAf54267DB7D7c367839AAf71A00a2C6A65';

        $this->tenant->run(function () use ($companyWallet, $customerWallet): void {
            CompanyProfile::singleton()->update(['wallet_address' => $companyWallet]);
            Customer::query()->whereKey($this->catalog['customer_id'])->update([
                'wallet_address' => $customerWallet,
            ]);
        });

        $id = $this->postServiceInvoice();

        $proofId = null;
        $hash = null;
        $this->tenant->run(function () use ($id, &$proofId, &$hash): void {
            $snapshot = InvoiceSnapshot::query()->where('invoice_id', $id)->firstOrFail();
            $proofId = (string) $snapshot->id;
            $hash = $snapshot->content_hash;
        });

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->expects($this->once())
            ->method('registerInvoice')
            ->with(
                $proofId,
                $hash,
                '0x90f79bf6eb2c4f870365e785982e1f101e93b906',
                '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            )
            ->willReturn(new InvoiceChainReceiptData(
                txHash: '0xabc',
                blockNumber: 1,
                contractAddress: '0x5fbdb2315678afecb367f032d93f642f64180aa3',
            ));
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $this->tenant->run(function () use ($proofId): void {
            app(InvoiceChainRegistrationService::class)->submitProof($proofId);
        });
    }

    public function test_verify_includes_supplier_eip712_when_waiting_company(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $id = $this->postServiceInvoice();

        $proofId = null;
        $hash = null;
        $this->tenant->run(function () use ($id, &$proofId, &$hash): void {
            $snapshot = InvoiceSnapshot::query()->where('invoice_id', $id)->firstOrFail();
            $proofId = (string) $snapshot->id;
            $hash = $snapshot->content_hash;
        });

        $supplier = '0x70997970c51812dc3a010c7d01b50e0d17dc79c8';
        $buyer = '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc';
        $registered = new InvoiceOnChainRecord(
            contentHash: $hash,
            supplierAddress: $supplier,
            buyerAddress: $buyer,
            supplierApproved: false,
            buyerApproved: false,
            status: InvoiceOnChainStatus::Registered,
        );

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->with($proofId)->willReturn($registered);
        $gateway->expects($this->never())->method('approveBySupplier');
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $data = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/verify"))
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_company')
            ->assertJsonPath('data.can_approve_as_company', true)
            ->assertJsonPath('data.can_approve_as_buyer', false)
            ->assertJsonPath('data.supplier_wallet', $supplier)
            ->assertJsonPath('data.buyer_wallet', $buyer)
            ->assertJsonPath('data.eip712.primary_type', 'SupplierApproval')
            ->json('data');

        $this->assertIsArray($data);
        $this->assertSame('InvoiceRegistry', $data['eip712']['domain']['name'] ?? null);
        $this->assertIsString($data['eip712']['message']['invoice_number'] ?? null);
        $this->assertNotSame('', $data['eip712']['message']['invoice_number'] ?? '');
        $this->assertStringStartsWith('Approve invoice ', (string) ($data['eip712']['message']['statement'] ?? ''));

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/approve-as-company"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'COMPANY_APPROVAL_REQUIRES_WALLET');
    }

    public function test_verify_ignores_live_invoice_price_quantity_date_and_line_changes(): void
    {
        $id = $this->postServiceInvoice();

        $this->tenant->run(function () use ($id): void {
            $line = SalesInvoiceLine::query()->where('sales_invoice_id', $id)->firstOrFail();
            $line->unit_price = '99';
            $line->save();
        });

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/verify"))
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.snapshot_intact', true)
            ->assertJsonPath('data.live_invoice_matches', false)
            ->assertJsonPath('data.chain_matches', null);

        $idQty = $this->postServiceInvoice();
        $this->tenant->run(function () use ($idQty): void {
            $line = SalesInvoiceLine::query()->where('sales_invoice_id', $idQty)->firstOrFail();
            $line->quantity = '5';
            $line->save();
        });
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$idQty}/verify"))
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.live_invoice_matches', false);

        $idDate = $this->postServiceInvoice();
        $this->tenant->run(function () use ($idDate): void {
            SalesInvoice::query()->whereKey($idDate)->update(['invoice_date' => '2026-01-01']);
        });
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$idDate}/verify"))
            ->assertJsonPath('data.status', 'verified');

        $idLine = $this->postServiceInvoice();
        $this->tenant->run(function () use ($idLine): void {
            SalesInvoiceLine::query()->where('sales_invoice_id', $idLine)->delete();
        });
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$idLine}/verify"))
            ->assertJsonPath('data.status', 'verified');
    }

    public function test_verify_detects_snapshot_json_tampering(): void
    {
        $id = $this->postServiceInvoice();

        $this->tenant->run(function () use ($id): void {
            $snapshot = InvoiceSnapshot::query()->where('invoice_id', $id)->firstOrFail();
            DB::table('invoice_snapshots')->where('id', $snapshot->id)->update([
                'canonical_json' => str_replace('50.0000', '99.0000', $snapshot->canonical_json),
            ]);
        });

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}/verify"))
            ->assertOk()
            ->assertJsonPath('data.status', 'tampered')
            ->assertJsonPath('data.snapshot_intact', false)
            ->assertJsonPath('data.live_invoice_matches', true);
    }

    public function test_post_issues_sale_stock_from_line_warehouse(): void
    {
        $this->tenant->run(function (): void {
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $this->catalog['stock_item_id'],
                warehouseId: $this->catalog['warehouse_id'],
                quantityDelta: '10',
                unitCost: '5',
                itemUomId: null,
                notes: 'test opening',
                userId: null,
            ));
        });

        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 3,
                    'unit_price' => 12,
                    'warehouse_id' => $this->catalog['warehouse_id'],
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk();

        $this->tenant->run(function () use ($id): void {
            $movement = StockMovement::query()
                ->where('reference_type', 'sales_invoice')
                ->where('reference_id', $id)
                ->where('item_id', $this->catalog['stock_item_id'])
                ->first();

            $this->assertNotNull($movement);
            $this->assertSame(StockMovementType::Sale, $movement->type);
            $this->assertSame('-3.000000', number_format((float) $movement->quantity_delta, 6, '.', ''));
            $this->assertSame($this->catalog['warehouse_id'], (int) $movement->warehouse_id);
        });
    }

    public function test_post_issues_bundle_components_as_bundle_sale(): void
    {
        $this->tenant->run(function (): void {
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $this->catalog['bundle_child_id'],
                warehouseId: $this->catalog['warehouse_id'],
                quantityDelta: '20',
                unitCost: '2',
                itemUomId: null,
                notes: 'bundle child opening',
                userId: null,
            ));
        });

        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['bundle_item_id'],
                    'quantity' => 2,
                    'unit_price' => 30,
                    'warehouse_id' => $this->catalog['warehouse_id'],
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk();

        $this->tenant->run(function () use ($id): void {
            $this->assertFalse(
                StockMovement::query()
                    ->where('reference_id', $id)
                    ->where('item_id', $this->catalog['bundle_item_id'])
                    ->exists()
            );

            $movement = StockMovement::query()
                ->where('reference_type', 'sales_invoice')
                ->where('reference_id', $id)
                ->where('item_id', $this->catalog['bundle_child_id'])
                ->first();

            $this->assertNotNull($movement);
            $this->assertSame(StockMovementType::BundleSale, $movement->type);
            $this->assertSame('-4.000000', number_format((float) $movement->quantity_delta, 6, '.', ''));
        });
    }

    public function test_post_rejects_insufficient_stock(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 1,
                    'unit_price' => 12,
                    'warehouse_id' => $this->catalog['warehouse_id'],
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_INSUFFICIENT');
    }

    public function test_posted_invoice_is_locked(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$id}"), ['notes' => 'locked'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_NOT_DRAFT');

        $this->asTenantRequest($this->token)
            ->deleteJson($this->tenantUrl("/sales-invoices/{$id}"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_NOT_DRAFT');
    }

    public function test_adjustment_cannot_make_grand_total_negative(): void
    {
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'adjustment' => -100,
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_NEGATIVE_TOTAL');
    }

    public function test_proof_portal_get_returns_locked_challenge_without_commercial_fields(): void
    {
        $id = $this->postServiceInvoice();

        $payload = $this->asTenantRequest(null)
            ->getJson($this->signedProofPortalUrl($id))
            ->assertOk()
            ->assertJsonPath('data.locked', true)
            ->json('data');

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('nonce', $payload);
        $this->assertArrayHasKey('message', $payload);
        $this->assertArrayHasKey('chain_id', $payload);
        $this->assertSame('0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc', $payload['buyer_wallet'] ?? null);
        $this->assertStringContainsString('This does not approve the invoice.', (string) $payload['message']);
        $this->assertArrayNotHasKey('id', $payload);
        $this->assertArrayNotHasKey('company_name', $payload);
        $this->assertArrayNotHasKey('customer_name', $payload);
        $this->assertArrayNotHasKey('invoice_number', $payload);
        $this->assertArrayNotHasKey('grand_total', $payload);
        $this->assertArrayNotHasKey('lines', $payload);
        $this->assertArrayNotHasKey('content_hash', $payload);
        $this->assertArrayNotHasKey('eip712', $payload);
        $this->assertArrayNotHasKey('can_approve_as_buyer', $payload);
        $this->assertArrayNotHasKey('status', $payload);
    }

    public function test_buyer_history_lists_posted_invoices_and_rejects_unknown_wallet(): void
    {
        $id = $this->postServiceInvoice();

        $challenge = $this->asTenantRequest(null)
            ->getJson($this->tenantUrl('/proofs/history'))
            ->assertOk()
            ->assertJsonPath('data.locked', true)
            ->json('data');

        $this->assertStringContainsString('does not approve any invoice.', (string) $challenge['message']);

        $signature = EthereumPersonalSign::sign(
            (string) $challenge['message'],
            '0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a',
        );

        $invoices = $this->asTenantRequest(null)
            ->postJson($this->tenantUrl('/proofs/history'), [
                'address' => '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC',
                'signature' => $signature,
                'nonce' => $challenge['nonce'],
            ])
            ->assertOk()
            ->json('data');

        $this->assertIsArray($invoices);
        $this->assertNotEmpty($invoices);
        $this->assertSame($id, $invoices[0]['id'] ?? null);
        $this->assertArrayHasKey('exp', $invoices[0]);
        $this->assertArrayHasKey('sig', $invoices[0]);

        $again = $this->asTenantRequest(null)
            ->getJson($this->tenantUrl('/proofs/history'))
            ->assertOk()
            ->json('data');
        $unknown = EthereumPersonalSign::sign(
            (string) $again['message'],
            '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80',
        );
        $this->asTenantRequest(null)
            ->postJson($this->tenantUrl('/proofs/history'), [
                'address' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
                'signature' => $unknown,
                'nonce' => $again['nonce'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROOF_BUYER_UNKNOWN');
    }

    public function test_proof_portal_unlock_returns_snapshot_and_omits_proof_internals(): void
    {
        $id = $this->postServiceInvoice();
        $invoiceNumber = null;
        $this->tenant->run(function () use ($id, &$invoiceNumber): void {
            $invoiceNumber = SalesInvoice::query()->whereKey($id)->value('invoice_number');
        });

        $payload = $this->unlockProofPortal($id);

        $this->assertFalse($payload['locked'] ?? true);
        $this->assertSame($id, $payload['id'] ?? null);
        $this->assertSame($invoiceNumber, $payload['invoice_number'] ?? null);
        $this->assertSame('2026-09-01', $payload['invoice_date'] ?? null);
        $this->assertSame('USD', $payload['currency_code'] ?? null);
        $this->assertSame('verified', $payload['status'] ?? null);
        $this->assertFalse($payload['can_approve_as_buyer'] ?? true);
        $this->assertArrayHasKey('company_name', $payload);
        $this->assertArrayHasKey('grand_total', $payload);
        $this->assertArrayHasKey('customer_name', $payload);
        $this->assertIsArray($payload['lines'] ?? null);
        $this->assertNotEmpty($payload['lines']);
        $this->assertArrayHasKey('item_name', $payload['lines'][0]);
        $this->assertArrayHasKey('description', $payload['lines'][0]);
        $this->assertArrayHasKey('quantity', $payload['lines'][0]);
        $this->assertArrayHasKey('unit_price', $payload['lines'][0]);
        $this->assertArrayHasKey('line_total', $payload['lines'][0]);
        $this->assertArrayHasKey('subtotal', $payload);
        $this->assertArrayHasKey('net_to_pay', $payload);
        $this->assertArrayNotHasKey('warehouse_id', $payload['lines'][0]);
        $this->assertArrayNotHasKey('item_id', $payload['lines'][0]);
        $this->assertArrayNotHasKey('notes', $payload['lines'][0]);
        $this->assertArrayNotHasKey('warehouse', $payload);
        $this->assertArrayNotHasKey('salesman', $payload);
        $this->assertArrayNotHasKey('billing_address', $payload);
        $this->assertArrayNotHasKey('payment_method', $payload);
        $this->assertArrayNotHasKey('snapshot_intact', $payload);
        $this->assertArrayNotHasKey('live_invoice_matches', $payload);
        $this->assertArrayNotHasKey('chain_matches', $payload);
        $this->assertArrayNotHasKey('can_approve_as_company', $payload);
        $this->assertArrayNotHasKey('canonical_json', $payload);
        $this->assertArrayHasKey('content_hash', $payload);
        $this->assertIsString($payload['content_hash']);
        $this->assertSame(64, strlen($payload['content_hash']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['content_hash']);
    }

    public function test_proof_portal_rejects_unsigned_link(): void
    {
        $id = $this->postServiceInvoice();

        $this->asTenantRequest(null)
            ->getJson($this->tenantUrl("/proofs/{$id}"))
            ->assertNotFound()
            ->assertJsonPath('code', 'PROOF_LINK_INVALID');
    }

    public function test_proof_portal_rejects_expired_link(): void
    {
        $id = $this->postServiceInvoice();

        $this->asTenantRequest(null)
            ->getJson($this->signedProofPortalUrl($id, time() - 60))
            ->assertForbidden()
            ->assertJsonPath('code', 'PROOF_LINK_EXPIRED');
    }

    public function test_proof_portal_rejects_wrong_signature(): void
    {
        $id = $this->postServiceInvoice();
        $exp = time() + 86400;

        $this->asTenantRequest(null)
            ->getJson($this->tenantUrl("/proofs/{$id}?exp={$exp}&sig=".str_repeat('ab', 32)))
            ->assertNotFound()
            ->assertJsonPath('code', 'PROOF_LINK_INVALID');
    }

    public function test_buyer_portal_link_returns_signed_url(): void
    {
        $id = $this->postServiceInvoice();

        $data = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/buyer-portal-link"))
            ->assertOk()
            ->json('data');

        $this->assertIsArray($data);
        $this->assertIsInt($data['exp'] ?? null);
        $this->assertIsString($data['sig'] ?? null);
        $this->assertIsString($data['url'] ?? null);
        $this->assertStringContainsString("proofs/{$id}", (string) $data['url']);
        $this->assertGreaterThan(time(), (int) $data['exp']);

        $this->asTenantRequest(null)
            ->getJson($this->tenantUrl((string) $data['url']))
            ->assertOk()
            ->assertJsonPath('data.locked', true);
    }

    public function test_buyer_portal_link_forbidden_without_sales_invoice_view(): void
    {
        $id = $this->postServiceInvoice();
        $token = $this->clerkTokenWithoutSalesInvoiceView();

        Auth::forgetGuards();

        $this->flushHeaders()
            ->asTenantRequest($token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/buyer-portal-link"))
            ->assertForbidden();
    }

    public function test_buyer_portal_link_rejected_for_draft(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/buyer-portal-link"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_NOT_POSTED');
    }

    public function test_proof_portal_shows_sealed_snapshot_not_live_invoice(): void
    {
        $id = $this->postServiceInvoice();
        $sealed = null;
        $this->tenant->run(function () use ($id, &$sealed): void {
            $snapshot = InvoiceSnapshot::query()->where('invoice_id', $id)->firstOrFail();
            $canonical = json_decode($snapshot->canonical_json, true);
            $this->assertIsArray($canonical);
            $sealed = $canonical;

            SalesInvoice::query()->whereKey($id)->update([
                'invoice_number' => 'SI-TAMPERED',
                'grand_total' => '999.0000',
            ]);
            SalesInvoiceLine::query()->where('sales_invoice_id', $id)->update([
                'unit_price' => '99.0000',
                'line_total' => '99.0000',
            ]);
        });

        $this->assertIsArray($sealed);

        $payload = $this->unlockProofPortal($id);
        $this->assertSame('verified', $payload['status'] ?? null);
        $this->assertFalse($payload['can_approve_as_buyer'] ?? true);
        $this->assertSame($sealed['invoice_number'], $payload['invoice_number'] ?? null);
        $this->assertSame($sealed['grand_total'], $payload['grand_total'] ?? null);
        $this->assertSame($sealed['lines'][0]['unit_price'], $payload['lines'][0]['unit_price'] ?? null);
        $this->assertSame($sealed['lines'][0]['line_total'], $payload['lines'][0]['line_total'] ?? null);
        $this->assertSame($sealed['lines'][0]['item_name'], $payload['lines'][0]['item_name'] ?? null);
    }

    public function test_proof_portal_not_found_when_snapshot_missing(): void
    {
        $id = $this->postServiceInvoice();
        $this->tenant->run(function () use ($id): void {
            DB::table('invoice_snapshots')->where('invoice_id', $id)->delete();
        });

        $this->asTenantRequest(null)
            ->getJson($this->signedProofPortalUrl($id))
            ->assertNotFound();
    }

    public function test_proof_portal_forbidden_when_invoice_proofs_disabled(): void
    {
        $this->setInvoiceProofsEnabled(false);
        $id = $this->postServiceInvoice();

        $this->asTenantRequest(null)
            ->getJson($this->signedProofPortalUrl($id))
            ->assertForbidden()
            ->assertJsonPath('code', 'INVOICE_PROOFS_DISABLED');
    }

    public function test_proof_portal_not_found_for_draft_invoice(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest(null)
            ->getJson($this->signedProofPortalUrl($id))
            ->assertNotFound();
    }

    public function test_proof_portal_includes_buyer_eip712_when_waiting_buyer(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $id = $this->postServiceInvoice();

        $proofId = null;
        $hash = null;
        $this->tenant->run(function () use ($id, &$proofId, &$hash): void {
            $snapshot = InvoiceSnapshot::query()->where('invoice_id', $id)->firstOrFail();
            $proofId = (string) $snapshot->id;
            $hash = $snapshot->content_hash;
        });

        $supplier = '0x70997970c51812dc3a010c7d01b50e0d17dc79c8';
        $buyer = '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc';
        $waitingBuyer = new InvoiceOnChainRecord(
            contentHash: $hash,
            supplierAddress: $supplier,
            buyerAddress: $buyer,
            supplierApproved: true,
            buyerApproved: false,
            status: InvoiceOnChainStatus::SupplierApproved,
        );

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->with($proofId)->willReturn($waitingBuyer);
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $data = $this->unlockProofPortal($id);
        $this->assertSame('waiting_buyer', $data['status'] ?? null);
        $this->assertTrue($data['can_approve_as_buyer'] ?? false);
        $this->assertSame($buyer, $data['buyer_wallet'] ?? null);
        $this->assertSame('0x5fbdb2315678afecb367f032d93f642f64180aa3', $data['contract_address'] ?? null);
        $this->assertSame($proofId, $data['proof_id'] ?? null);
        $this->assertSame('BuyerApproval', $data['eip712']['primary_type'] ?? null);
        $this->assertTrue($data['can_dispute_as_buyer'] ?? false);
        $this->assertSame('BuyerDispute', $data['dispute_eip712']['primary_type'] ?? null);
        $this->assertStringStartsWith('Dispute invoice ', (string) ($data['dispute_eip712']['message']['statement'] ?? ''));
        $this->assertStringStartsWith('Approve invoice ', (string) ($data['eip712']['message']['statement'] ?? ''));

        $this->assertIsArray($data);
        $this->assertIsArray($data['eip712'] ?? null);
        $this->assertSame('InvoiceRegistry', $data['eip712']['domain']['name'] ?? null);
        $this->assertArrayNotHasKey('approve_by_buyer_data', $data);
        $this->assertArrayNotHasKey('snapshot_intact', $data);
        $this->assertSame($supplier, $data['supplier_wallet'] ?? null);
        $this->assertArrayNotHasKey('can_approve_as_company', $data);
    }

    public function test_proof_portal_stores_dispute_reason_when_chain_hash_matches(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $id = $this->postServiceInvoice();
        [$proofId, $hash] = $this->confirmedProof($id);
        $reason = 'qty is wrong';
        $reasonHash = InvoiceProofBytes::keccakUtf8($reason);
        $disputed = new InvoiceOnChainRecord(
            contentHash: $hash,
            supplierAddress: '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            buyerAddress: '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
            supplierApproved: true,
            buyerApproved: false,
            status: InvoiceOnChainStatus::Disputed,
            disputedAt: 1700001000,
            disputeReasonHash: $reasonHash,
        );
        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->with($proofId)->willReturn($disputed);
        $gateway->method('attestationsOf')->willReturn([]);
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $stamp = $this->proofPortalStamp($id);
        $this->unlockProofPortal($id, $stamp);

        $this->asTenantRequest(null)
            ->postJson($this->tenantUrl("/proofs/{$id}/dispute?exp={$stamp['exp']}&sig={$stamp['sig']}"), [
                'reason' => $reason,
                'tx_hash' => '0x'.str_repeat('ab', 32),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'disputed')
            ->assertJsonPath('data.dispute_reason', $reason)
            ->assertJsonPath('data.can_approve_as_buyer', false)
            ->assertJsonPath('data.can_dispute_as_buyer', false);

        $this->tenant->run(function () use ($proofId, $reason, $reasonHash): void {
            $registration = InvoiceChainRegistration::query()->where('proof_id', $proofId)->sole();
            $this->assertSame($reason, $registration->dispute_reason);
            $this->assertSame($reasonHash, $registration->dispute_reason_hash);
            $this->assertNotNull($registration->disputed_at);
        });

        $this->asTenantRequest(null)
            ->postJson($this->tenantUrl("/proofs/{$id}/dispute?exp={$stamp['exp']}&sig={$stamp['sig']}"), [
                'reason' => 'a different reason',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVOICE_PROOF_DISPUTE_REASON_MISMATCH');
    }

    public function test_proof_portal_omits_supplier_eip712_when_waiting_company(): void
    {
        Queue::fake();
        $this->enableBlockchainConfig();
        $id = $this->postServiceInvoice();

        $proofId = null;
        $hash = null;
        $this->tenant->run(function () use ($id, &$proofId, &$hash): void {
            $snapshot = InvoiceSnapshot::query()->where('invoice_id', $id)->firstOrFail();
            $proofId = (string) $snapshot->id;
            $hash = $snapshot->content_hash;
        });

        $waitingCompany = new InvoiceOnChainRecord(
            contentHash: $hash,
            supplierAddress: '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            buyerAddress: '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
            supplierApproved: false,
            buyerApproved: false,
            status: InvoiceOnChainStatus::Registered,
        );

        $gateway = $this->createMock(InvoiceRegistryGateway::class);
        $gateway->method('invoiceOf')->with($proofId)->willReturn($waitingCompany);
        $this->app->instance(InvoiceRegistryGateway::class, $gateway);

        $data = $this->unlockProofPortal($id);
        $this->assertSame('waiting_company', $data['status'] ?? null);
        $this->assertFalse($data['can_approve_as_buyer'] ?? true);
        $this->assertArrayHasKey('eip712', $data);
        $this->assertNull($data['eip712']);
    }

    public function test_proof_portal_unlock_rejects_wrong_wallet(): void
    {
        $id = $this->postServiceInvoice();
        $stamp = $this->proofPortalStamp($id);
        $challenge = $this->asTenantRequest(null)
            ->getJson($this->signedProofPortalUrl($id, $stamp['exp'], $stamp['sig']))
            ->assertOk()
            ->json('data');

        $wrongKey = '0x7c852118294e51e653712a81e05800f419141751be58f605c371e15141b007a6';
        $signature = EthereumPersonalSign::sign((string) $challenge['message'], $wrongKey);

        $this->asTenantRequest(null)
            ->postJson($this->signedProofPortalUnlockUrl($id, $stamp['exp'], $stamp['sig']), [
                'address' => '0x90F79bf6EB2c4f870365E785982E1f101E93b906',
                'signature' => $signature,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROOF_WALLET_MISMATCH');

        $payload = $this->unlockProofPortal($id, $stamp);
        $this->assertSame($id, $payload['id'] ?? null);
    }

    public function test_proof_portal_unlock_accepts_owner_of_buyer_safe(): void
    {
        $buyerSafe = '0x1111111111111111111111111111111111111111';
        $this->fakeSafeOwners([$buyerSafe => ['0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc']]);

        $id = $this->postServiceInvoice();
        $this->tenant->run(function () use ($id, $buyerSafe): void {
            $invoice = SalesInvoice::query()->whereKey($id)->firstOrFail();
            Customer::query()->whereKey($invoice->customer_id)->update(['wallet_address' => $buyerSafe]);
        });

        $stamp = $this->proofPortalStamp($id);
        $challenge = $this->asTenantRequest(null)
            ->getJson($this->signedProofPortalUrl($id, $stamp['exp'], $stamp['sig']))
            ->assertOk()
            ->json('data');

        $strangerKey = '0x7c852118294e51e653712a81e05800f419141751be58f605c371e15141b007a6';
        $this->asTenantRequest(null)
            ->postJson($this->signedProofPortalUnlockUrl($id, $stamp['exp'], $stamp['sig']), [
                'address' => '0x90F79bf6EB2c4f870365E785982E1f101E93b906',
                'signature' => EthereumPersonalSign::sign((string) $challenge['message'], $strangerKey),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROOF_WALLET_MISMATCH');

        $payload = $this->unlockProofPortal($id, $stamp);
        $this->assertSame($id, $payload['id'] ?? null);
    }

    public function test_buyer_safe_controlled_by_company_signer_is_rejected(): void
    {
        $companyOwner = '0x9965507d1a55bcc2695c58ba16fb37d819b0a4dc';
        $this->fakeSafeOwners([
            '0x70997970c51812dc3a010c7d01b50e0d17dc79c8' => [$companyOwner],
            '0x1111111111111111111111111111111111111111' => [$companyOwner],
            '0x2222222222222222222222222222222222222222' => ['0x15d34aaf54267db7d7c367839aaf71a00a2c6a65'],
        ]);

        $this->tenant->run(function (): void {
            $guard = app(CompanySafeSignerGuard::class);
            $guard->assertCustomerWalletAllowed('0x2222222222222222222222222222222222222222');

            try {
                $guard->assertCustomerWalletAllowed('0x1111111111111111111111111111111111111111');
                $this->fail('A buyer Safe owned by a company signer must be rejected.');
            } catch (CompanySignerWalletException $exception) {
                $this->assertSame(CompanySafeSignerGuard::CUSTOMER_ERROR_CODE, $exception->errorCode);
            }
        });
    }

    public function test_invoice_verifier_rejects_safe_controlled_by_company_signer(): void
    {
        $this->fakeSafeOwners([
            '0x70997970c51812dc3a010c7d01b50e0d17dc79c8' => ['0x9965507d1a55bcc2695c58ba16fb37d819b0a4dc'],
            '0x1111111111111111111111111111111111111111' => ['0x9965507d1a55bcc2695c58ba16fb37d819b0a4dc'],
        ]);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/invoice-verifiers'), [
                'name' => 'Captured bank',
                'role' => 'financier',
                'wallet_address' => '0x1111111111111111111111111111111111111111',
                'wallet_type' => 'safe',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['wallet_address']);
    }

    public function test_declared_wallet_type_must_match_the_chain(): void
    {
        $safe = '0x1111111111111111111111111111111111111111';
        $wallet = '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65';
        $this->fakeSafeOwners([$safe => ['0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc']]);

        $this->tenant->run(function () use ($safe, $wallet): void {
            $guard = app(CompanySafeSignerGuard::class);
            $guard->assertWalletType($safe, WalletType::Safe);
            $guard->assertWalletType($wallet, WalletType::Wallet);

            foreach ([
                [$safe, WalletType::Wallet, CompanySafeSignerGuard::WALLET_IS_CONTRACT_CODE],
                [$wallet, WalletType::Safe, CompanySafeSignerGuard::SAFE_NOT_FOUND_CODE],
            ] as [$address, $type, $code]) {
                try {
                    $guard->assertWalletType($address, $type);
                    $this->fail("{$address} declared as {$type->value} must be rejected.");
                } catch (CompanySignerWalletException $exception) {
                    $this->assertSame($code, $exception->errorCode);
                }
            }
        });

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/invoice-verifiers'), [
                'name' => 'Bank Safe',
                'role' => 'financier',
                'wallet_address' => $wallet,
                'wallet_type' => 'safe',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', CompanySafeSignerGuard::SAFE_NOT_FOUND_CODE);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/invoice-verifiers'), [
                'name' => 'Bank Safe',
                'role' => 'financier',
                'wallet_address' => $safe,
                'wallet_type' => 'safe',
            ])
            ->assertCreated()
            ->assertJsonPath('data.wallet_type', 'safe');
    }

    public function test_wallet_inspection_reports_kind_and_owners(): void
    {
        $safe = '0x1111111111111111111111111111111111111111';
        $this->fakeSafeOwners([$safe => ['0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc']]);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/wallet-inspection?address={$safe}"))
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.kind', 'safe')
            ->assertJsonPath('data.owners.0', '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc')
            ->assertJsonPath('data.threshold', 1);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/wallet-inspection?address=0x15d34AAf54267DB7D7c367839AAf71A00a2C6A65'))
            ->assertOk()
            ->assertJsonPath('data.kind', 'wallet');
    }

    public function test_proof_portal_unlock_nonce_is_one_use(): void
    {
        $id = $this->postServiceInvoice();
        $stamp = $this->proofPortalStamp($id);
        $challenge = $this->asTenantRequest(null)
            ->getJson($this->signedProofPortalUrl($id, $stamp['exp'], $stamp['sig']))
            ->assertOk()
            ->json('data');

        $signature = EthereumPersonalSign::sign(
            (string) $challenge['message'],
            '0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a',
        );
        $body = [
            'address' => '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC',
            'signature' => $signature,
        ];

        $this->asTenantRequest(null)
            ->postJson($this->signedProofPortalUnlockUrl($id, $stamp['exp'], $stamp['sig']), $body)
            ->assertOk()
            ->assertJsonPath('data.locked', false);

        $this->asTenantRequest(null)
            ->postJson($this->signedProofPortalUnlockUrl($id, $stamp['exp'], $stamp['sig']), $body)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROOF_UNLOCK_INVALID');
    }

    public function test_proof_portal_unlock_rejects_unsigned_link(): void
    {
        $id = $this->postServiceInvoice();

        $this->asTenantRequest(null)
            ->postJson($this->tenantUrl("/proofs/{$id}/unlock"), [
                'address' => '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC',
                'signature' => '0x'.str_repeat('ab', 65),
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'PROOF_LINK_INVALID');
    }

    public function test_proof_portal_unlock_requires_buyer_wallet(): void
    {
        $id = $this->postServiceInvoice();
        $this->tenant->run(function () use ($id): void {
            $invoice = SalesInvoice::query()->whereKey($id)->firstOrFail();
            Customer::query()->whereKey($invoice->customer_id)->update(['wallet_address' => null]);
        });

        $stamp = $this->proofPortalStamp($id);
        $challenge = $this->asTenantRequest(null)
            ->getJson($this->signedProofPortalUrl($id, $stamp['exp'], $stamp['sig']))
            ->assertOk()
            ->json('data');

        $this->assertTrue($challenge['locked'] ?? false);
        $this->assertArrayHasKey('buyer_wallet', $challenge);
        $this->assertNull($challenge['buyer_wallet']);

        $signature = EthereumPersonalSign::sign(
            (string) $challenge['message'],
            '0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a',
        );

        $this->asTenantRequest(null)
            ->postJson($this->signedProofPortalUnlockUrl($id, $stamp['exp'], $stamp['sig']), [
                'address' => '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC',
                'signature' => $signature,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROOF_WALLET_REQUIRED');
    }

    public function test_due_on_defaults_from_payment_terms(): void
    {
        $created = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'invoice_date' => '2026-09-01',
                'payment_terms_id' => $this->catalog['payment_term_id'],
            ]))
            ->assertCreated();

        $this->assertSame('2026-09-11', $created->json('data.due_on'));
    }

    private function postServiceInvoice(): string
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk();

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseInvoicePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->catalog['customer_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'currency_id' => $this->catalog['usd_id'],
            'invoice_date' => '2026-09-01',
        ], $overrides);
    }

    private function setInvoiceProofsEnabled(bool $enabled): void
    {
        $this->tenant->run(function () use ($enabled): void {
            CompanySetting::singleton()->update([
                'invoice_proofs_enabled' => $enabled,
            ]);
            app(CompanySettingService::class)->forgetCache();
        });
    }

    private function clerkTokenWithoutInvoiceProofsView(bool $grantEdit = true): string
    {
        return $this->tenant->run(function () use ($grantEdit): string {
            $role = Role::query()->create([
                'name' => $grantEdit ? 'Sales Clerk No Proof View' : 'Sales Clerk No Proof Access',
                'is_active' => true,
            ]);

            $permissions = Permission::query()->orderBy('resource_key')->get();
            foreach ($permissions as $permission) {
                $isProofs = $permission->resource_key === 'invoice_proofs';
                $flags = RbacResourceCatalog::clampFlags($permission->resource_key, [
                    'can_view' => ! $isProofs,
                    'can_add' => true,
                    'can_edit' => ! $isProofs || $grantEdit,
                    'can_delete' => true,
                    'can_import' => true,
                    'can_export' => true,
                ]);
                RolePermission::query()->create([
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                    ...$flags,
                ]);
            }

            app(PermissionService::class)->invalidateCacheForAllUsers();

            $clerk = User::factory()->create([
                'name' => 'Sales Clerk View',
                'email' => 'clerk-view@sales-invoice-test.local',
                'is_active' => true,
            ]);
            app(BranchService::class)->assignUserToDefaultBranch($clerk, (int) $role->id);

            $this->assertFalse(
                app(PermissionService::class)->userHas('invoice_proofs', 'view', $clerk->fresh() ?? $clerk),
            );

            return $clerk->createToken('sales-invoice-clerk-view')->plainTextToken;
        });
    }

    private function clerkTokenWithoutInvoiceProofsEdit(): string
    {
        return $this->tenant->run(function (): string {
            $role = Role::query()->create([
                'name' => 'Sales Clerk No Proof Approve',
                'is_active' => true,
            ]);

            $permissions = Permission::query()->orderBy('resource_key')->get();
            foreach ($permissions as $permission) {
                $grantEdit = $permission->resource_key !== 'invoice_proofs';
                $flags = RbacResourceCatalog::clampFlags($permission->resource_key, [
                    'can_view' => true,
                    'can_add' => true,
                    'can_edit' => $grantEdit,
                    'can_delete' => true,
                    'can_import' => true,
                    'can_export' => true,
                ]);
                RolePermission::query()->create([
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                    ...$flags,
                ]);
            }

            app(PermissionService::class)->invalidateCacheForAllUsers();

            $clerk = User::factory()->create([
                'name' => 'Sales Clerk',
                'email' => 'clerk@sales-invoice-test.local',
                'is_active' => true,
            ]);
            app(BranchService::class)->assignUserToDefaultBranch($clerk, (int) $role->id);

            $this->assertFalse(
                app(PermissionService::class)->userHas('invoice_proofs', 'edit', $clerk->fresh() ?? $clerk),
            );

            return $clerk->createToken('sales-invoice-clerk')->plainTextToken;
        });
    }

    private function clerkTokenWithoutSalesInvoiceView(): string
    {
        return $this->tenant->run(function (): string {
            $role = Role::query()->create([
                'name' => 'No Sales Invoice View',
                'is_active' => true,
            ]);

            $permissions = Permission::query()->orderBy('resource_key')->get();
            foreach ($permissions as $permission) {
                $grantView = $permission->resource_key !== 'sales_invoices';
                $flags = RbacResourceCatalog::clampFlags($permission->resource_key, [
                    'can_view' => $grantView,
                    'can_add' => true,
                    'can_edit' => true,
                    'can_delete' => true,
                    'can_import' => true,
                    'can_export' => true,
                ]);
                RolePermission::query()->create([
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                    ...$flags,
                ]);
            }

            app(PermissionService::class)->invalidateCacheForAllUsers();

            $clerk = User::factory()->create([
                'name' => 'No View Clerk',
                'email' => 'noview@sales-invoice-test.local',
                'is_active' => true,
            ]);
            app(BranchService::class)->assignUserToDefaultBranch($clerk, (int) $role->id);

            return $clerk->createToken('sales-invoice-noview')->plainTextToken;
        });
    }

    private function signedProofPortalUrl(string $invoiceId, ?int $exp = null, ?string $sig = null): string
    {
        $stamp = $exp !== null && $sig !== null
            ? ['exp' => $exp, 'sig' => $sig]
            : $this->proofPortalStamp($invoiceId, $exp);

        return $this->tenantUrl("/proofs/{$invoiceId}?exp={$stamp['exp']}&sig={$stamp['sig']}");
    }

    /**
     * @return array{exp: int, sig: string}
     */
    private function proofPortalStamp(string $invoiceId, ?int $exp = null): array
    {
        $exp ??= time() + (7 * 86400);
        $tenantId = (string) $this->tenant->getTenantKey();

        return [
            'exp' => $exp,
            'sig' => ProofPortalLink::sign($tenantId, $invoiceId, $exp),
        ];
    }

    /**
     * @param  array<string, list<string>>  $ownersBySafe  Lowercase addresses; anything else reads as a plain wallet.
     */
    private function fakeSafeOwners(array $ownersBySafe): void
    {
        $this->app->instance(CompanySafeOwnerLookup::class, new class($ownersBySafe) implements CompanySafeOwnerLookup
        {
            /**
             * @param  array<string, list<string>>  $ownersBySafe
             */
            public function __construct(private readonly array $ownersBySafe) {}

            public function ownersOf(string $safeAddress): array
            {
                return $this->ownersBySafe[strtolower($safeAddress)] ?? [];
            }

            public function inspect(string $address): WalletInspectionData
            {
                $owners = $this->ownersOf($address);

                return $owners === []
                    ? WalletInspectionData::wallet(strtolower($address))
                    : WalletInspectionData::safe(strtolower($address), $owners, 1);
            }
        });
    }

    private function signedProofPortalUnlockUrl(string $invoiceId, int $exp, string $sig): string
    {
        return $this->tenantUrl("/proofs/{$invoiceId}/unlock?exp={$exp}&sig={$sig}");
    }

    /**
     * @param  array{exp: int, sig: string}|null  $stamp
     * @return array<string, mixed>
     */
    private function unlockProofPortal(string $invoiceId, ?array $stamp = null): array
    {
        $stamp ??= $this->proofPortalStamp($invoiceId);
        $challenge = $this->asTenantRequest(null)
            ->getJson($this->signedProofPortalUrl($invoiceId, $stamp['exp'], $stamp['sig']))
            ->assertOk()
            ->json('data');

        $this->assertTrue($challenge['locked'] ?? false);
        $signature = EthereumPersonalSign::sign(
            (string) $challenge['message'],
            '0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a',
        );
        $this->assertIsString($signature);

        $payload = $this->asTenantRequest(null)
            ->postJson($this->signedProofPortalUnlockUrl($invoiceId, $stamp['exp'], $stamp['sig']), [
                'address' => '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC',
                'signature' => $signature,
            ])
            ->assertOk()
            ->json('data');

        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @return array{0: string, 1: string} proof id and content hash
     */
    private function confirmedProof(string $invoiceId): array
    {
        return $this->tenant->run(function () use ($invoiceId): array {
            $snapshot = InvoiceSnapshot::query()->where('invoice_id', $invoiceId)->firstOrFail();
            InvoiceChainRegistration::query()->where('proof_id', $snapshot->id)->update([
                'status' => 'confirmed',
                'contract_address' => '0x5fbdb2315678afecb367f032d93f642f64180aa3',
            ]);

            return [(string) $snapshot->id, (string) $snapshot->content_hash];
        });
    }

    private function chainRecord(string $hash): InvoiceOnChainRecord
    {
        return new InvoiceOnChainRecord(
            contentHash: $hash,
            supplierAddress: '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            buyerAddress: '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
            supplierApproved: false,
            buyerApproved: false,
            status: InvoiceOnChainStatus::Registered,
        );
    }

    private function enableBlockchainConfig(): void
    {
        config([
            'blockchain.enabled' => true,
            'blockchain.rpc_url' => 'http://127.0.0.1:8545',
            'blockchain.chain_id' => 31337,
            'blockchain.contract_address' => '0x5FbDB2315678afecb367f032d93F642f64180aa3',
            'blockchain.registrar_address' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
        ]);
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
            'tax_enabled' => true,
            'tax_price_mode' => 'exclusive',
            'allow_negative_stock' => false,
            'invoice_proofs_enabled' => true,
        ]);
        app(CompanySettingService::class)->forgetCache();

        CurrencyPairRate::query()->create([
            'from_currency_id' => $eur->id,
            'to_currency_id' => $usd->id,
            'rate' => 3.5,
            'effective_from' => now(),
        ]);

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
            'abrv' => 'VAT10',
            'name' => 'VAT 10',
            'percentage' => 10,
            'is_default' => true,
            'is_active' => true,
        ]);

        $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
        $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
        $serviceType = ItemType::query()->where('code', 'SERVICE')->firstOrFail();
        $inventoryType = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();
        $bundleType = ItemType::query()->where('code', 'BUNDLE')->firstOrFail();

        $service = $this->makeItem('Service item', 'SVC-1', $serviceType->id, $unitGroup->id, $uom->id, $vat->id, false);
        $stock = $this->makeItem('Stock item', 'STK-1', $inventoryType->id, $unitGroup->id, $uom->id, $vat->id, true);
        $child = $this->makeItem('Bundle child', 'BND-C', $inventoryType->id, $unitGroup->id, $uom->id, $vat->id, true);
        $bundle = $this->makeItem('Bundle parent', 'BND-P', $bundleType->id, $unitGroup->id, $uom->id, $vat->id, false);

        BundleItem::query()->create([
            'bundle_item_id' => $bundle->id,
            'child_item_id' => $child->id,
            'quantity' => 2,
        ]);

        CompanyProfile::singleton()->update([
            'wallet_address' => '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
        ]);

        $customer = Customer::query()->create([
            'name' => 'Invoice Customer',
            'type' => 'individual',
            'status' => 'active',
            'wallet_address' => '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC',
        ]);

        $term = PaymentTerm::query()->create([
            'code' => 'NET10',
            'name' => 'Net 10',
            'due_days' => 10,
            'is_default' => false,
            'is_active' => true,
        ]);

        return [
            'usd_id' => (int) $usd->id,
            'eur_id' => (int) $eur->id,
            'warehouse_id' => (int) $warehouse->id,
            'customer_id' => (string) $customer->id,
            'service_item_id' => (string) $service->id,
            'stock_item_id' => (string) $stock->id,
            'bundle_item_id' => (string) $bundle->id,
            'bundle_child_id' => (string) $child->id,
            'payment_term_id' => (int) $term->id,
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
        ]);

        return $item;
    }
}
