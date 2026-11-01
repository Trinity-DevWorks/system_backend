<?php

declare(strict_types=1);

namespace App\Modules\DocumentNumbering\Services;

use App\Models\DocumentNumberSetting;
use App\Modules\Branch\Models\Branch;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use App\Support\TenantReferenceCache;
use Illuminate\Support\Facades\DB;

/**
 * Read and save the number format of each document type (prefix, parts, padding, first number).
 */
class DocumentNumberSettingService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $lastByType = DB::table('document_sequences')
            ->selectRaw('document_type, MAX(last_value) as last_value')
            ->groupBy('document_type')
            ->pluck('last_value', 'document_type');

        $branch = Branch::query()->where('is_default', true)->orderBy('id')->first() ?? Branch::query()->orderBy('id')->first();
        $branchCode = $branch !== null ? $this->numbers->branchCode($branch) : 'B1';

        $rows = [];
        foreach (DocumentType::cases() as $type) {
            $settings = DocumentNumberService::settingsFor($type);
            $last = (int) ($lastByType[$type->value] ?? 0);
            $rows[] = [
                'document_type' => $type->value,
                'group' => $type->group(),
                'default_prefix' => $type->defaultPrefix(),
                ...$settings,
                'last_number' => $last,
                'preview' => $this->numbers->preview($settings, $branchCode, max($last + 1, (int) $settings['start_number'])),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function update(array $rows): void
    {
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $type = DocumentType::from((string) $row['document_type']);
                $current = DocumentNumberService::settingsFor($type);
                $next = [
                    'prefix' => strtoupper(trim((string) ($row['prefix'] ?? $current['prefix']))),
                    'include_branch' => (bool) ($row['include_branch'] ?? $current['include_branch']),
                    'include_year' => (bool) ($row['include_year'] ?? $current['include_year']),
                    'padding' => (int) ($row['padding'] ?? $current['padding']),
                    'start_number' => (int) ($row['start_number'] ?? $current['start_number']),
                ];

                // A start number only takes effect when it is above what was already issued;
                // refuse lower values so the user is not misled.
                if ($next['start_number'] !== (int) $current['start_number']) {
                    $last = (int) DB::table('document_sequences')->where('document_type', $type->value)->max('last_value');
                    if ($next['start_number'] <= $last) {
                        abort(422, sprintf('The first number for %s must be above %d (already issued).', $type->value, $last), [
                            'X-Error-Code' => 'DOCUMENT_NUMBER_START_TOO_LOW',
                        ]);
                    }
                }

                DocumentNumberSetting::query()->updateOrCreate(['document_type' => $type->value], $next);
            }
        });

        TenantReferenceCache::forget(DocumentNumberService::CACHE_KEY);
    }
}
