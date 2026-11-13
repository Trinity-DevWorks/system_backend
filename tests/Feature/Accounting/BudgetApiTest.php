<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Branch\Models\Branch;
use App\Modules\Currency\Models\Currency;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class BudgetApiTest extends TestCase
{
    use InteractsWithTenant;

    private string $token;

    private int $rentId;

    private int $salesId;

    private int $expenseGroupId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->token = $this->tenantBearerToken();
        $this->tenant->run(function (): void {
            $this->expenseGroupId = (int) Account::query()->where('code', '6')->value('id');
            $this->rentId = (int) Account::query()->where('code', '6011')->value('id');
            $this->salesId = (int) Account::query()->where('code', '7011')->value('id');
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();
        parent::tearDown();
    }

    public function test_budget_grid_spread_approve_and_revise(): void
    {
        $created = $this->callJson('POST', 'budgets', [
            'name' => '2027 Plan',
            'year' => 2027,
            'notes' => 'Draft plan',
        ]);
        $created->assertCreated();
        $created->assertJsonPath('data.name', '2027 Plan');
        $created->assertJsonPath('data.status', 'draft');
        $created->assertJsonPath('data.version', 1);
        $created->assertJsonPath('data.fiscal_year_start', '2027-01-01');
        $id = (string) $created->json('data.id');

        $spread = $this->callJson('POST', "budgets/{$id}/spread", [
            'account_id' => $this->rentId,
            'amount' => '100',
        ]);
        $spread->assertOk();
        $rent = $this->row($spread->json('data.rows'), '6011');
        $group = $this->row($spread->json('data.rows'), '6');
        $this->assertSame('8.3333', $rent['amounts']['2027-01-01']);
        $this->assertSame('8.3337', $rent['amounts']['2027-12-01']);
        $this->assertSame('100.0000', $rent['total']);
        $this->assertSame('100.0000', $group['total']);
        $this->assertFalse($this->hasCode($spread->json('data.rows'), '5310'));

        $this->callJson('POST', "budgets/{$id}/spread", [
            'account_id' => $this->expenseGroupId,
            'amount' => '12',
        ])->assertStatus(422)->assertJsonPath('code', 'BUDGET_ACCOUNT_INVALID');

        $this->callJson('PUT', "budgets/{$id}/lines", [
            'lines' => [[
                'account_id' => $this->salesId,
                'period_month' => '2026-01-01',
                'amount' => '10',
            ]],
        ])->assertStatus(422)->assertJsonPath('code', 'BUDGET_MONTH_OUT_OF_RANGE');

        $list = $this->callJson('GET', 'budgets');
        $list->assertOk();
        $this->assertSame('100.0000', $list->json('data.data.0.expense_total'));

        $approved = $this->callJson('POST', "budgets/{$id}/approve");
        $approved->assertOk();
        $approved->assertJsonPath('data.status', 'approved');

        $this->callJson('PUT', "budgets/{$id}", ['name' => 'Changed'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BUDGET_NOT_DRAFT');

        $second = $this->callJson('POST', 'budgets', ['name' => 'Other 2027', 'year' => 2027]);
        $secondId = (string) $second->json('data.id');
        $this->callJson('POST', "budgets/{$secondId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'BUDGET_ALREADY_APPROVED_FOR_YEAR');

        $branchId = $this->tenant->run(fn (): int => (int) Branch::query()->create([
            'name' => 'North',
            'shortcut_name' => 'NTH',
            'is_active' => true,
        ])->id);
        $branchBudget = $this->callJson('POST', 'budgets', [
            'name' => 'North 2027',
            'year' => 2027,
            'branch_id' => $branchId,
        ]);
        $this->callJson('POST', 'budgets/'.$branchBudget->json('data.id').'/approve')->assertOk();

        $revised = $this->callJson('POST', "budgets/{$id}/revise");
        $revised->assertOk();
        $revised->assertJsonPath('data.status', 'draft');
        $revised->assertJsonPath('data.version', 2);
        $revised->assertJsonPath('data.parent_budget_id', $id);
        $this->callJson('GET', "budgets/{$id}")->assertJsonPath('data.status', 'archived');

        $revisedId = (string) $revised->json('data.id');
        $grid = $this->callJson('GET', "budgets/{$revisedId}/grid");
        $this->assertSame('100.0000', $this->row($grid->json('data.rows'), '6011')['total']);

        $this->callJson('DELETE', "budgets/{$secondId}")->assertOk();
        $this->callJson('DELETE', "budgets/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'BUDGET_NOT_DRAFT');
    }

    public function test_copy_actuals_excludes_closing_entries_and_applies_percent(): void
    {
        $this->tenant->run(function (): void {
            $currency = Currency::query()->create([
                'name' => 'US Dollar',
                'code' => 'USD',
                'iso_code' => 'USD',
                'symbol' => '$',
                'is_active' => true,
            ]);
            $bankId = (int) Account::query()->where('code', '5310')->value('id');
            $this->postEntry('actual', '2026-01-15', [
                [$this->rentId, '1000', '0', $currency->id],
                [$bankId, '0', '1000', $currency->id],
                [$this->salesId, '0', '500', $currency->id],
                [$bankId, '500', '0', $currency->id],
            ]);
            $this->postEntry('close', '2026-01-31', [
                [$this->rentId, '9000', '0', $currency->id],
                [$bankId, '0', '9000', $currency->id],
            ], JournalEntry::SOURCE_YEAR_END_CLOSE);
        });

        $created = $this->callJson('POST', 'budgets', ['name' => 'From actuals', 'year' => 2027]);
        $id = (string) $created->json('data.id');
        $copied = $this->callJson('POST', "budgets/{$id}/copy-actuals", [
            'year' => 2026,
            'percent' => 10,
        ]);
        $copied->assertOk();
        $rent = $this->row($copied->json('data.rows'), '6011');
        $sales = $this->row($copied->json('data.rows'), '7011');
        $this->assertSame('1100.0000', $rent['amounts']['2027-01-01']);
        $this->assertSame('0.0000', $rent['amounts']['2027-02-01']);
        $this->assertSame('550.0000', $sales['amounts']['2027-01-01']);
    }

    public function test_excel_round_trip_and_invalid_row(): void
    {
        $created = $this->callJson('POST', 'budgets', ['name' => 'Sheet', 'year' => 2027]);
        $id = (string) $created->json('data.id');
        $this->callJson('POST', "budgets/{$id}/spread", [
            'account_id' => $this->rentId,
            'amount' => '36000',
        ])->assertOk();

        $export = $this->asTenantRequest($this->token)->get($this->tenantUrl("budgets/{$id}/export"));
        $export->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $export->baseResponse);
        $copy = storage_path('framework/testing-budget.xlsx');
        copy($export->baseResponse->getFile()->getPathname(), $copy);

        $this->callJson('PUT', "budgets/{$id}/lines", [
            'lines' => [[
                'account_id' => $this->rentId,
                'period_month' => '2027-01-01',
                'amount' => '0',
            ]],
        ])->assertOk();

        $imported = $this->asTenantRequest($this->token)->post($this->tenantUrl("budgets/{$id}/import"), [
            'file' => new UploadedFile($copy, 'budget.xlsx', null, null, true),
        ]);
        $imported->assertOk();
        $this->assertSame('3000.0000', $this->row($imported->json('data.rows'), '6011')['amounts']['2027-01-01']);
        @unlink($copy);

        $bad = storage_path('framework/testing-budget-bad.xlsx');
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([
            ['Budget'],
            [],
            ['code', 'name', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06', '2027-07', '2027-08', '2027-09', '2027-10', '2027-11', '2027-12'],
            ['NOPE', 'Missing', 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
        ]);
        (new Xlsx($sheet))->save($bad);

        $failed = $this->asTenantRequest($this->token)->post($this->tenantUrl("budgets/{$id}/import"), [
            'file' => new UploadedFile($bad, 'bad.xlsx', null, null, true),
        ]);
        $failed->assertStatus(422);
        $failed->assertJsonPath('code', 'BUDGET_IMPORT_INVALID');
        $this->assertSame('NOPE', $failed->json('details.rows.0.code'));
        @unlink($bad);
    }

    public function test_company_budget_warning_settings(): void
    {
        $settings = $this->callJson('GET', 'company-settings');
        $settings->assertOk();
        $settings->assertJsonPath('data.budget_warnings_enabled', true);
        $settings->assertJsonPath('data.budget_warning_scope', 'month');

        $this->callJson('PUT', 'company-settings', ['budget_warning_scope' => 'ytd'])
            ->assertOk()
            ->assertJsonPath('data.budget_warning_scope', 'ytd');
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string, 3: int}>  $lines
     */
    private function postEntry(string $sourceId, string $date, array $lines, string $sourceType = JournalEntry::SOURCE_MANUAL): void
    {
        $debit = '0';
        $credit = '0';
        foreach ($lines as $line) {
            $debit = bcadd($debit, $line[1], 4);
            $credit = bcadd($credit, $line[2], 4);
        }

        $entry = JournalEntry::query()->create([
            'entry_date' => $date,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_event' => JournalEntry::EVENT_POST,
            'description' => $sourceId,
            'status' => 'posted',
            'total_debit' => $debit,
            'total_credit' => $credit,
        ]);

        foreach ($lines as $index => $line) {
            JournalEntryLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line[0],
                'debit' => $line[1],
                'credit' => $line[2],
                'currency_id' => $line[3],
                'exchange_rate' => '1',
                'amount_currency' => bcsub($line[1], $line[2], 4),
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return array<string, mixed>
     */
    private function row(?array $rows, string $code): array
    {
        foreach ($rows ?? [] as $row) {
            if (($row['code'] ?? null) === $code) {
                return $row;
            }
        }

        $this->fail("Account {$code} was not in the grid.");
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     */
    private function hasCode(?array $rows, string $code): bool
    {
        foreach ($rows ?? [] as $row) {
            if (($row['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    private function callJson(string $method, string $path, array $data = []): TestResponse
    {
        return $this->asTenantRequest($this->token)->json($method, $this->tenantUrl($path), $data);
    }
}
