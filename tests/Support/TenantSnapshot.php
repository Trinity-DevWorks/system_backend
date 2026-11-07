<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Builds a test company once per test run and resets it between tests.
 *
 * The first test that asks for a tenant migrates and seeds it (slow). Its data is then copied
 * into a "<tenant>__snapshot" schema. Every later test only truncates the tenant tables and
 * copies the snapshot back, which takes a fraction of a second.
 *
 * State lives here (not in the trait) so it is shared by all test classes in the run.
 */
final class TenantSnapshot
{
    /** @var array<string, array{user_id: string, tables: list<string>, restore_sql: string}> */
    private static array $built = [];

    /**
     * @return array{user_id: string, tables: list<string>, restore_sql: string}|null
     */
    public static function get(string $tenantId): ?array
    {
        return self::$built[$tenantId] ?? null;
    }

    public static function forget(string $tenantId): void
    {
        unset(self::$built[$tenantId]);
    }

    /** Copies the freshly built tenant's data into its snapshot schema. */
    public static function capture(string $tenantId, string $userId): void
    {
        $schema = self::quote($tenantId);
        $snapshot = self::quote(self::snapshotSchema($tenantId));
        $tables = self::tables($tenantId);

        $copy = ['DROP SCHEMA IF EXISTS '.$snapshot.' CASCADE', 'CREATE SCHEMA '.$snapshot];
        foreach ($tables as $table) {
            $name = self::quote($table);
            $copy[] = "CREATE TABLE {$snapshot}.{$name} AS TABLE {$schema}.{$name}";
        }
        DB::unprepared(implode(";\n", $copy).';');

        self::$built[$tenantId] = [
            'user_id' => $userId,
            'tables' => $tables,
            'restore_sql' => self::restoreSql($tenantId, $tables),
        ];
    }

    /** Puts the tenant back exactly as it was right after it was built (one round trip). */
    public static function restore(string $tenantId): void
    {
        $sql = self::$built[$tenantId]['restore_sql'] ?? '';
        if ($sql !== '') {
            DB::unprepared($sql);
        }
    }

    public static function dropSnapshot(string $tenantId): void
    {
        DB::statement('DROP SCHEMA IF EXISTS '.self::quote(self::snapshotSchema($tenantId)).' CASCADE');
    }

    /**
     * The whole reset as one SQL script, prepared once: empty every table, copy back the tables
     * that had rows, then put each id sequence back where it stood after the build.
     *
     * @param  list<string>  $tables
     */
    private static function restoreSql(string $tenantId, array $tables): string
    {
        if ($tables === []) {
            return '';
        }

        $schema = self::quote($tenantId);
        $snapshot = self::quote(self::snapshotSchema($tenantId));
        $qualified = array_map(fn (string $table): string => $schema.'.'.self::quote($table), $tables);

        // Tables that hold seed data, and where each sequence stood right after the build.
        $filled = array_map(
            fn (object $row): string => (string) $row->relname,
            DB::select('SELECT relname FROM pg_stat_user_tables WHERE schemaname = ? AND n_live_tup > 0', [$tenantId]),
        );
        $filled = array_values(array_filter($tables, function (string $table) use ($schema, $filled): bool {
            return in_array($table, $filled, true)
                || DB::selectOne("SELECT EXISTS (SELECT 1 FROM {$schema}.".self::quote($table).') AS present')->present;
        }));
        $sequences = DB::select(
            "SELECT quote_ident(schemaname) || '.' || quote_ident(sequencename) AS seq, last_value
             FROM pg_sequences WHERE schemaname = ?",
            [$tenantId],
        );

        $statements = [
            'BEGIN',
            // Tables are refilled in any order, so foreign keys are not checked during the copy.
            'SET LOCAL session_replication_role = replica',
        ];
        // DELETE, not TRUNCATE: on mostly-empty tables it is far cheaper (TRUNCATE rewrites every table file).
        foreach ($qualified as $table) {
            $statements[] = 'DELETE FROM '.$table;
        }
        foreach ($filled as $table) {
            $name = self::quote($table);
            $statements[] = "INSERT INTO {$schema}.{$name} OVERRIDING SYSTEM VALUE SELECT * FROM {$snapshot}.{$name}";
        }
        foreach ($sequences as $sequence) {
            $name = str_replace("'", "''", (string) $sequence->seq);
            // A sequence never used during the build goes back to 1.
            $statements[] = $sequence->last_value === null
                ? "SELECT setval('{$name}', 1, false)"
                : "SELECT setval('{$name}', ".(int) $sequence->last_value.', true)';
        }
        $statements[] = 'COMMIT';

        return implode(";\n", $statements).';';
    }

    /**
     * @return list<string>
     */
    private static function tables(string $tenantId): array
    {
        $rows = DB::select(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name",
            [$tenantId],
        );

        return array_values(array_map(fn (object $row): string => (string) $row->table_name, $rows));
    }

    private static function snapshotSchema(string $tenantId): string
    {
        return $tenantId.'__snapshot';
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
