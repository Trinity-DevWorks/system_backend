<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Support\InventoryCostingMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Money, quantity, cost and exchange-rate helpers must stay exact (bcmath via App\Support\Decimal).
 * A float cast or number_format() in these files would silently reintroduce rounding drift.
 */
class ExactMathGuardTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function exactFiles(): array
    {
        $files = [
            'app/Support/Decimal.php',
            'app/Support/DocumentTaxMath.php',
            'app/Support/PaymentAllocation.php',
            'app/Support/CurrencyCashRound.php',
            'app/Support/ExchangeRateSnapshot.php',
            'app/Support/ProportionalCredit.php',
            'app/Modules/CompanySetting/Support/PriceMath.php',
            'app/Modules/Accounting/Support/JournalMath.php',
            'app/Modules/Inventory/Stock/Support/InventoryCostingMath.php',
            'app/Modules/Inventory/Stock/Support/ProductionScale.php',
            'app/Modules/Inventory/Stock/Support/BundleExplosionScale.php',
            'app/Modules/Inventory/Stock/Support/ReplenishmentAlertRules.php',
            'app/Modules/Inventory/Stock/Support/StockAdjustmentQuantity.php',
            'app/Modules/Sales/SalesInvoice/Support/SalesInvoiceLineQuantity.php',
            'app/Modules/Sales/Support/CommercialLineQuantity.php',
            'app/Modules/Inventory/Purchasing/Support/PurchaseInvoiceLineQuantity.php',
            'app/Modules/Inventory/Purchasing/Support/PurchaseOrderLineQuantity.php',
            'app/Modules/Inventory/Stock/Support/StockTransferLineQuantity.php',
        ];

        return array_combine($files, array_map(static fn (string $f): array => [$f], $files));
    }

    #[DataProvider('exactFiles')]
    public function test_file_has_no_float_math(string $relative): void
    {
        $path = dirname(__DIR__, 2).'/'.$relative;
        $this->assertFileExists($path);
        $code = (string) file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression('/\(float\)|floatval\(|number_format\(|\bfloat \$/', $code, $relative.' must use App\Support\Decimal instead of floats.');
    }

    public function test_unit_cost_keeps_six_decimals_and_values_round_half_up(): void
    {
        // 1 kg bought for 12.50 => cost per gram 0.0125; a 3-gram issue is worth 0.0375.
        $perGram = InventoryCostingMath::unitCostFromValue('1000', '12.5000');
        $this->assertSame('0.012500', $perGram);
        $this->assertSame('0.0375', InventoryCostingMath::value('3', $perGram));

        // 7 units for 10.00: 1.428571 (rounded, not truncated) and the value rounds half-up.
        $this->assertSame('1.428571', InventoryCostingMath::unitCostFromValue('7', '10'));
        $this->assertSame('0.0001', InventoryCostingMath::money('0.00005'));
        $this->assertSame('0.000013', InventoryCostingMath::unitCost('0.0000125'));
    }
}
