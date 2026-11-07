<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DocumentNumberSetting;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What: Issues document numbers like SI-B1-2026-000001 (prefix, branch code, year, counter).
 *       The parts, prefix, padding and first number are configurable per document type
 *       (document_number_settings); the defaults give the format above.
 * Where: Document services, inside their post() transaction (create() for quotations/orders/POs).
 * Why: Numbers must be gapless. The counter row is incremented with an atomic upsert that keeps
 *      the row locked until the caller's transaction ends, so a failed post rolls the counter back
 *      and two concurrent posts never share a number.
 */
final class DocumentNumberService
{
    public const CACHE_KEY = 'document_number_settings.all';

    public const DEFAULT_PADDING = 6;

    /** Longest branch code kept in the number; longer shortcuts fall back to B{id}. */
    private const MAX_BRANCH_CODE = 10;

    /** @var array<int, string> */
    private array $branchCodes = [];

    /**
     * Next number for a document type (per branch and/or year, depending on its settings).
     * Must run inside a database transaction (that is what makes it gapless).
     */
    public function next(DocumentType $type, ?int $branchId, mixed $date): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Document numbers must be issued inside a database transaction.');
        }

        $settings = self::settingsFor($type);
        $branch = $settings['include_branch'] ? $this->resolveBranch($branchId) : null;
        $year = $settings['include_year'] ? (int) Carbon::parse($date ?? now())->format('Y') : 0;
        $scope = $branch !== null ? 'B'.$branch->id : '*';
        $start = max(1, (int) $settings['start_number']);

        $row = DB::selectOne(
            'INSERT INTO document_sequences (document_type, scope, year, last_value, created_at, updated_at)
             VALUES (?, ?, ?, ?, now(), now())
             ON CONFLICT (document_type, scope, year)
             DO UPDATE SET last_value = GREATEST(document_sequences.last_value + 1, EXCLUDED.last_value), updated_at = now()
             RETURNING last_value',
            [$type->value, $scope, $year, $start],
        );

        return $this->format($settings, $branch !== null ? $this->branchCode($branch) : null, $year, (int) ($row->last_value ?? 0));
    }

    /**
     * Give a document its number the first time it is posted; posted/reversed documents keep theirs.
     */
    public function assign(Model $document, string $column, DocumentType $type, ?int $branchId, mixed $date): string
    {
        $current = $document->getAttribute($column);
        if (is_string($current) && $current !== '') {
            return $current;
        }

        $number = $this->next($type, $branchId, $date);
        $document->forceFill([$column => $number])->save();

        return $number;
    }

    /**
     * Example of the next number (does not reserve it).
     *
     * @param  array{prefix: string, include_branch: bool, include_year: bool, padding: int, start_number: int}  $settings
     */
    public function preview(array $settings, ?string $branchCode = 'B1', ?int $number = null): string
    {
        return $this->format(
            $settings,
            $settings['include_branch'] ? ($branchCode ?? 'B1') : null,
            $settings['include_year'] ? (int) now()->format('Y') : 0,
            $number ?? max(1, (int) $settings['start_number']),
        );
    }

    /**
     * Effective settings for a type (stored row or defaults).
     *
     * @return array{prefix: string, include_branch: bool, include_year: bool, padding: int, start_number: int}
     */
    public static function settingsFor(DocumentType $type): array
    {
        $all = TenantReferenceCache::remember(self::CACHE_KEY, fn (): array => DocumentNumberSetting::query()
            ->get()
            ->mapWithKeys(fn (DocumentNumberSetting $s): array => [$s->document_type => [
                'prefix' => $s->prefix,
                'include_branch' => (bool) $s->include_branch,
                'include_year' => (bool) $s->include_year,
                'padding' => (int) $s->padding,
                'start_number' => (int) $s->start_number,
            ]])
            ->all());

        return is_array($all) && isset($all[$type->value]) ? $all[$type->value] : self::defaults($type);
    }

    /**
     * @return array{prefix: string, include_branch: bool, include_year: bool, padding: int, start_number: int}
     */
    public static function defaults(DocumentType $type): array
    {
        return [
            'prefix' => $type->defaultPrefix(),
            'include_branch' => true,
            'include_year' => true,
            'padding' => self::DEFAULT_PADDING,
            'start_number' => 1,
        ];
    }

    /**
     * @param  array{prefix: string, include_branch: bool, include_year: bool, padding: int, start_number: int}  $settings
     */
    private function format(array $settings, ?string $branchCode, int $year, int $number): string
    {
        $parts = [strtoupper(trim($settings['prefix']))];
        if ($branchCode !== null) {
            $parts[] = $branchCode;
        }
        if ($year > 0) {
            $parts[] = (string) $year;
        }
        $parts[] = str_pad((string) $number, max(1, (int) $settings['padding']), '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }

    private function resolveBranch(?int $branchId): Branch
    {
        $branch = $branchId !== null ? Branch::query()->find($branchId) : null;
        $branch ??= Branch::query()->where('is_default', true)->orderBy('id')->first()
            ?? Branch::query()->orderBy('id')->first();

        if (! $branch instanceof Branch) {
            abort(422, 'Create a branch before posting documents.', ['X-Error-Code' => 'DOCUMENT_BRANCH_REQUIRED']);
        }

        return $branch;
    }

    public function branchCode(Branch $branch): string
    {
        $id = (int) $branch->id;
        if (! isset($this->branchCodes[$id])) {
            $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $branch->shortcut_name));
            $this->branchCodes[$id] = ($code === '' || strlen($code) > self::MAX_BRANCH_CODE) ? 'B'.$id : $code;
        }

        return $this->branchCodes[$id];
    }
}
