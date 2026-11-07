<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Branch\Models\Branch;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Per-document-type number formats: prefix, branch/year parts, padding and first number.
 */
#[Group('tenant-db')]
class DocumentNumberSettingsApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant('doc_numbering_v1');
        $this->token = $this->tenantBearerToken();
        $this->branchId = (int) $this->tenant->run(fn () => Branch::query()->create([
            'name' => 'East', 'shortcut_name' => 'EST', 'is_active' => true, 'is_default' => false,
        ])->id);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();
        parent::tearDown();
    }

    public function test_lists_every_type_with_defaults_and_preview(): void
    {
        $rows = collect($this->asTenantRequest($this->token)->getJson($this->tenantUrl('/document-number-settings'))
            ->assertOk()
            ->json('data'))->keyBy('document_type');

        $this->assertCount(count(DocumentType::cases()), $rows);
        $this->assertSame('SI', $rows['sales_invoice']['prefix']);
        $this->assertTrue($rows['sales_invoice']['include_branch']);
        $this->assertMatchesRegularExpression('/^SI-[A-Z0-9]+-\d{4}-000001$/', $rows['sales_invoice']['preview']);
        $this->assertSame('DN', $rows['delivery_note']['prefix']);
    }

    public function test_prefix_change_keeps_the_counter_and_parts_are_optional(): void
    {
        $this->assertSame('SI-EST-2026-000001', $this->next(DocumentType::SalesInvoice));

        $this->save(['document_type' => 'sales_invoice', 'prefix' => 'INV', 'include_branch' => true, 'include_year' => true, 'padding' => 6, 'start_number' => 1]);
        $this->assertSame('INV-EST-2026-000002', $this->next(DocumentType::SalesInvoice));

        // One shared series, no year, 4 digits.
        $this->save(['document_type' => 'customer_receipt', 'prefix' => 'RC', 'include_branch' => false, 'include_year' => false, 'padding' => 4, 'start_number' => 1]);
        $this->assertSame('RC-0001', $this->next(DocumentType::CustomerReceipt));
        $this->assertSame('RC-0002', $this->next(DocumentType::CustomerReceipt, null));
    }

    public function test_start_number_must_be_above_issued_numbers(): void
    {
        $this->next(DocumentType::SalesInvoice);
        $this->next(DocumentType::SalesInvoice);

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/document-number-settings'), ['settings' => [[
                'document_type' => 'sales_invoice', 'prefix' => 'SI', 'include_branch' => true, 'include_year' => true, 'padding' => 6, 'start_number' => 2,
            ]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_NUMBER_START_TOO_LOW');

        $this->save(['document_type' => 'sales_invoice', 'prefix' => 'SI', 'include_branch' => true, 'include_year' => true, 'padding' => 6, 'start_number' => 500]);
        $this->assertSame('SI-EST-2026-000500', $this->next(DocumentType::SalesInvoice));
        $this->assertSame('SI-EST-2026-000501', $this->next(DocumentType::SalesInvoice));
    }

    public function test_validation_limits_keep_numbers_short(): void
    {
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/document-number-settings'), ['settings' => [[
                'document_type' => 'sales_invoice', 'prefix' => 'TOOLONG', 'include_branch' => true, 'include_year' => true, 'padding' => 9, 'start_number' => 1,
            ]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settings.0.prefix', 'settings.0.padding']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function save(array $row): void
    {
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/document-number-settings'), ['settings' => [$row]])
            ->assertOk();
    }

    private function next(DocumentType $type, ?int $branchId = -1): string
    {
        $branch = $branchId === -1 ? $this->branchId : $branchId;

        return $this->tenant->run(fn (): string => DB::transaction(
            fn (): string => app(DocumentNumberService::class)->next($type, $branch, '2026-05-01')
        ));
    }
}
