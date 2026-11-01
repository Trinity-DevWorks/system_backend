<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Cash/bank accounts, transfers between them and expense vouchers (journals, numbering, reversal, period lock).
 */
#[Group('tenant-db')]
class TreasuryApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const CASH = '5310';

    private const BANK = '5120';

    private const EUR_BANK = '5110';

    private const RENT = '6263';

    private const VAT_INPUT = '44210';

    private const EXCHANGE_GAIN = '7751';

    private const OPENING_EQUITY = '1201';

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('treasury_v1');
        $this->token = $this->tenantBearerToken();
        $this->catalog = $this->tenant->run(fn (): array => $this->seedCatalog());
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_cash_bank_accounts_require_an_asset_ledger_account(): void
    {
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/cash-bank-accounts'), $this->accountPayload('BAD', self::RENT, 'usd_id'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TREASURY_LEDGER_ACCOUNT_INVALID');

        $id = $this->createAccount('CASH1', self::CASH, 'usd_id', 'cash');
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.type', 'cash')
            ->assertJsonPath('data.balance', '0.0000');
    }

    public function test_same_currency_transfer_posts_numbers_and_reverses(): void
    {
        $cash = $this->createAccount('CASH1', self::CASH, 'usd_id', 'cash', 500);
        $bank = $this->createAccount('BANK1', self::BANK, 'usd_id', 'bank');

        $id = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/treasury-transfers'), [
                'transfer_date' => '2026-06-10',
                'from_account_id' => $cash,
                'to_account_id' => $bank,
                'amount_from' => 100,
            ])
            ->assertCreated()
            ->assertJsonPath('data.transfer_number', null)
            ->assertJsonPath('data.amount_to', '100.0000')
            ->json('data.id');

        $posted = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/treasury-transfers/{$id}/post"))->assertOk();
        $this->assertMatchesRegularExpression('/^TRF-[A-Z0-9]+-2026-000001$/', (string) $posted->json('data.transfer_number'));

        $this->assertSame(['100.0000', '-100.0000'], [$this->net('treasury_transfer', $id, self::BANK), $this->net('treasury_transfer', $id, self::CASH)]);
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$bank}"))
            ->assertJsonPath('data.balance', '100.0000');

        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/treasury-transfers/{$id}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$bank}"))
            ->assertJsonPath('data.balance', '0.0000');

        // Posted / reversed documents cannot be edited or deleted.
        $this->asTenantRequest($this->token)->deleteJson($this->tenantUrl("/treasury-transfers/{$id}"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TREASURY_DOCUMENT_NOT_DRAFT');
    }

    public function test_cross_currency_transfer_books_exchange_difference(): void
    {
        $eurBank = $this->createAccount('EURB', self::EUR_BANK, 'eur_id', 'bank');
        $cash = $this->createAccount('CASH1', self::CASH, 'usd_id', 'cash');

        // 350 EUR at 3.5 EUR per USD = 100 USD out; 110 USD received => 10 USD exchange gain.
        $id = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/treasury-transfers'), [
                'transfer_date' => '2026-06-10',
                'from_account_id' => $eurBank,
                'to_account_id' => $cash,
                'amount_from' => 350,
                'amount_to' => 110,
                'exchange_rate' => 3.5,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/treasury-transfers/{$id}/post"))->assertOk();

        $this->assertSame('110.0000', $this->net('treasury_transfer', $id, self::CASH));
        $this->assertSame('-100.0000', $this->net('treasury_transfer', $id, self::EUR_BANK));
        $this->assertSame('-10.0000', $this->net('treasury_transfer', $id, self::EXCHANGE_GAIN));
    }

    public function test_expense_voucher_posts_expense_vat_and_cash(): void
    {
        $cash = $this->createAccount('CASH1', self::CASH, 'usd_id', 'cash', 500);

        $id = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/expense-vouchers'), [
                'voucher_date' => '2026-06-15',
                'cash_bank_account_id' => $cash,
                'payee' => 'Landlord',
                'lines' => [[
                    'account_id' => $this->accountId(self::RENT),
                    'description' => 'June rent',
                    'amount' => 100,
                    'vat_group_id' => $this->catalog['vat_id'],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', '111.0000')
            ->json('data.id');

        $posted = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/expense-vouchers/{$id}/post"))->assertOk();
        $this->assertMatchesRegularExpression('/^EXP-[A-Z0-9]+-2026-000001$/', (string) $posted->json('data.voucher_number'));

        $this->assertSame('100.0000', $this->net('expense_voucher', $id, self::RENT));
        $this->assertSame('11.0000', $this->net('expense_voucher', $id, self::VAT_INPUT));
        $this->assertSame('-111.0000', $this->net('expense_voucher', $id, self::CASH));

        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/expense-vouchers/{$id}/reverse"))->assertOk();
        $this->assertSame('0.0000', $this->net('expense_voucher', $id, self::CASH));
    }

    public function test_expense_voucher_rejects_non_expense_accounts_and_closed_periods(): void
    {
        $cash = $this->createAccount('CASH1', self::CASH, 'usd_id', 'cash', 500);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/expense-vouchers'), [
                'voucher_date' => '2026-06-15',
                'cash_bank_account_id' => $cash,
                'lines' => [['account_id' => $this->accountId(self::BANK), 'amount' => 10]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'EXPENSE_VOUCHER_ACCOUNT_INVALID');

        $id = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/expense-vouchers'), [
                'voucher_date' => '2026-03-01',
                'cash_bank_account_id' => $cash,
                'lines' => [['account_id' => $this->accountId(self::RENT), 'amount' => 10]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->tenant->run(fn () => CompanySetting::singleton()->update(['books_closed_through' => '2026-03-31']));
        app(CompanySettingService::class)->forgetCache();

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/expense-vouchers/{$id}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERIOD_LOCKED');
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/expense-vouchers/{$id}"))
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.voucher_number', null);
    }

    public function test_opening_balance_posts_and_follows_edits(): void
    {
        $cash = $this->createAccount('CASH1', self::CASH, 'usd_id', 'cash', 1000);

        $this->assertSame('1000.0000', $this->net('cash_bank_opening_balance', (string) $cash, self::CASH));
        $this->assertSame('-1000.0000', $this->net('cash_bank_opening_balance', (string) $cash, self::OPENING_EQUITY));
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$cash}"))
            ->assertJsonPath('data.balance', '1000.0000')
            ->assertJsonPath('data.opening_balance', '1000.0000')
            ->assertJsonPath('data.opening_date', '2026-01-01');

        // Editing the amount replaces the opening entry; zero removes it.
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/cash-bank-accounts/{$cash}"), ['opening_balance' => 750])
            ->assertOk();
        $this->assertSame('750.0000', $this->net('cash_bank_opening_balance', (string) $cash, self::CASH));
        $this->assertSame(1, $this->openingEntries());

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/cash-bank-accounts/{$cash}"), ['opening_balance' => 0])
            ->assertOk();
        $this->assertSame(0, $this->openingEntries());

        // No date given: the opening balance is dated today. The names list carries balances.
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/cash-bank-accounts'), $this->accountPayload('BANK1', self::BANK, 'usd_id', 'bank') + ['opening_balance' => 40])
            ->assertCreated()
            ->assertJsonPath('data.opening_date', now()->toDateString());
        $names = $this->asTenantRequest($this->token)->getJson($this->tenantUrl('/cash-bank-accounts?section=names'))->assertOk()->json('data');
        $this->assertSame('40.0000', collect($names)->firstWhere('code', 'BANK1')['balance'] ?? null);
    }

    public function test_cash_cannot_pay_out_more_than_it_holds_but_bank_can(): void
    {
        $cash = $this->createAccount('CASH1', self::CASH, 'usd_id', 'cash', 100);
        $bank = $this->createAccount('BANK1', self::BANK, 'usd_id', 'bank');

        $tooMuch = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/treasury-transfers'), [
                'transfer_date' => '2026-06-10', 'from_account_id' => $cash, 'to_account_id' => $bank, 'amount_from' => 150,
            ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/treasury-transfers/{$tooMuch}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TREASURY_INSUFFICIENT_BALANCE');
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/treasury-transfers/{$tooMuch}"))
            ->assertJsonPath('data.status', 'draft');

        $voucher = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/expense-vouchers'), [
                'voucher_date' => '2026-06-15', 'cash_bank_account_id' => $cash,
                'lines' => [['account_id' => $this->accountId(self::RENT), 'amount' => 120]],
            ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/expense-vouchers/{$voucher}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TREASURY_INSUFFICIENT_BALANCE');

        // A bank account may go into overdraft.
        $overdraft = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/treasury-transfers'), [
                'transfer_date' => '2026-06-10', 'from_account_id' => $bank, 'to_account_id' => $cash, 'amount_from' => 300,
            ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/treasury-transfers/{$overdraft}/post"))->assertOk();
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$bank}"))
            ->assertJsonPath('data.balance', '-300.0000');

        // With 400 in cash now, the earlier transfer can be posted.
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/treasury-transfers/{$tooMuch}/post"))->assertOk();
    }

    private function openingEntries(): int
    {
        return (int) $this->tenant->run(fn () => JournalEntry::query()->where('source_type', 'cash_bank_opening_balance')->count());
    }

    /**
     * Primary-currency debit minus credit on one ledger account for a document's journals (post + reverse).
     */
    private function net(string $sourceType, string $sourceId, string $code): string
    {
        return $this->tenant->run(function () use ($sourceType, $sourceId, $code): string {
            $entryIds = JournalEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->pluck('id');
            $accountId = Account::query()->where('code', $code)->value('id');
            $row = JournalEntryLine::query()
                ->whereIn('journal_entry_id', $entryIds)
                ->where('account_id', $accountId)
                ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
                ->first();

            return bcadd((string) ($row?->getAttribute('net') ?? '0'), '0', 4);
        });
    }

    private function createAccount(string $code, string $ledgerCode, string $currencyKey, string $type, float $opening = 0): int
    {
        $payload = $this->accountPayload($code, $ledgerCode, $currencyKey, $type);
        if ($opening > 0) {
            $payload += ['opening_balance' => $opening, 'opening_date' => '2026-01-01'];
        }

        return (int) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/cash-bank-accounts'), $payload)
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPayload(string $code, string $ledgerCode, string $currencyKey, string $type = 'cash'): array
    {
        return [
            'code' => $code,
            'name' => 'Account '.$code,
            'type' => $type,
            'currency_id' => $this->catalog[$currencyKey],
            'account_id' => $this->accountId($ledgerCode),
        ];
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
        $usd = Currency::query()->create(['name' => 'US Dollar', 'code' => 'USD', 'iso_code' => 'USD', 'symbol' => '$', 'is_active' => true]);
        $eur = Currency::query()->create(['name' => 'Euro', 'code' => 'EUR', 'iso_code' => 'EUR', 'symbol' => '€', 'is_active' => true]);

        CompanySetting::singleton()->update([
            'primary_currency_id' => $usd->id,
            'tax_enabled' => true,
            'tax_price_mode' => 'exclusive',
            'price_decimal_places' => 2,
        ]);
        app(CompanySettingService::class)->forgetCache();
        app(ExchangeRateService::class)->setPairRate($usd->id, $eur->id, '3.5');
        app(ChartOfAccountsService::class)->seedFromTemplate();

        $vat = VatGroup::query()->create(['abrv' => 'VAT11', 'name' => 'VAT 11', 'percentage' => 11, 'is_default' => true, 'is_active' => true]);

        return [
            'usd_id' => (int) $usd->id,
            'eur_id' => (int) $eur->id,
            'vat_id' => (int) $vat->id,
        ];
    }
}
