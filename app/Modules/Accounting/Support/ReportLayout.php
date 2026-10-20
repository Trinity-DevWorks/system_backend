<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\CompanySetting\Models\CompanySetting;

/**
 * Classifies accounts into the report sections of the tenant's chart template.
 * The first section whose code prefix matches, and whose types (when set)
 * contain the account type, wins. Anything left over is unclassified.
 */
final class ReportLayout
{
    public function templateKey(): string
    {
        $stored = CompanySetting::query()->value('chart_template');
        $templates = (array) config('chart_of_accounts.templates', []);
        if (is_string($stored) && $stored !== '' && isset($templates[$stored])) {
            return $stored;
        }

        return (string) config('chart_of_accounts.default_template');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function incomeStatement(): array
    {
        $sections = $this->layout()['income_statement'] ?? [];

        return is_array($sections) ? array_values($sections) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function balanceSheet(string $side): array
    {
        $sides = $this->layout()['balance_sheet'] ?? [];
        $sections = is_array($sides) ? ($sides[$side] ?? []) : [];

        return is_array($sections) ? array_values($sections) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    public function match(Account $account, array $sections): ?string
    {
        $code = (string) $account->code;
        $type = $account->type?->value;

        foreach ($sections as $section) {
            $prefixes = $section['prefixes'] ?? null;
            if (! is_array($prefixes) || $prefixes === []) {
                continue;
            }
            $matches = false;
            foreach ($prefixes as $prefix) {
                $prefix = (string) $prefix;
                if ($prefix !== '' && str_starts_with($code, $prefix)) {
                    $matches = true;

                    break;
                }
            }
            if (! $matches) {
                continue;
            }
            $types = $section['types'] ?? null;
            if (is_array($types) && $types !== [] && ($type === null || ! in_array($type, $types, true))) {
                continue;
            }

            return (string) $section['key'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function layout(): array
    {
        $layout = config('chart_of_accounts.templates.'.$this->templateKey().'.report_layout');

        return is_array($layout) ? $layout : [];
    }
}
