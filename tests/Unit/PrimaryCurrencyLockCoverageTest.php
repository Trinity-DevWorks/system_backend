<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Currency\Services\CurrencyService;
use PHPUnit\Framework\TestCase;

/**
 * The primary currency is locked once documents exist (CURRENCY_PRIMARY_LOCKED). This guards the
 * list: a new tenant table with an exchange rate, or a new business document (issuing branch plus a
 * document number), must be added to CurrencyService::PRIMARY_DEPENDENT_TABLES.
 */
class PrimaryCurrencyLockCoverageTest extends TestCase
{
    public function test_every_document_table_locks_the_primary_currency(): void
    {
        $missing = array_values(array_diff(
            $this->documentTables(),
            CurrencyService::PRIMARY_DEPENDENT_TABLES,
        ));

        $this->assertSame([], $missing, 'Add these tables to CurrencyService::PRIMARY_DEPENDENT_TABLES: '.implode(', ', $missing));
    }

    public function test_detection_covers_known_documents(): void
    {
        $tables = $this->documentTables();

        foreach (['sales_quotations', 'purchase_orders', 'customer_refunds', 'stock_counts', 'stock_transfers', 'journal_entry_lines'] as $table) {
            $this->assertContains($table, $tables);
        }
    }

    public function test_listed_tables_exist(): void
    {
        $columns = $this->tenantColumns();

        foreach (CurrencyService::PRIMARY_DEPENDENT_TABLES as $table) {
            $this->assertArrayHasKey($table, $columns, "{$table} is listed but no tenant migration creates it.");
        }
    }

    /**
     * @return list<string>
     */
    private function documentTables(): array
    {
        $tables = [];
        foreach ($this->tenantColumns() as $table => $columns) {
            $hasRate = in_array('exchange_rate', $columns, true);
            $hasBranch = array_intersect(['branch_id', 'from_branch_id'], $columns) !== [];
            $hasNumber = array_filter($columns, fn (string $column): bool => str_ends_with($column, '_number')) !== [];

            if ($hasRate || ($hasBranch && $hasNumber)) {
                $tables[] = $table;
            }
        }
        sort($tables);

        return $tables;
    }

    /**
     * @return array<string, list<string>>
     */
    private function tenantColumns(): array
    {
        $columns = [];
        $files = glob(dirname(__DIR__, 2).'/database/migrations/tenant/*.php') ?: [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/Schema::(?:create|table)\(\'(\w+)\',\s*function[^{]*\{(.*?)\n\s*\}\);/s', $source, $blocks, PREG_SET_ORDER);
            foreach ($blocks as $block) {
                preg_match_all('/\$table->\w+\(\'(\w+)\'/', $block[2], $names);
                $columns[$block[1]] = array_values(array_unique([...($columns[$block[1]] ?? []), ...$names[1]]));
            }
        }

        return $columns;
    }
}
