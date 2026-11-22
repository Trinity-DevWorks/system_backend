<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\FixedAsset\Enums\DepreciationRunStatus;
use App\Modules\Accounting\FixedAsset\Enums\FixedAssetStatus;
use App\Modules\Accounting\FixedAsset\Models\DepreciationLine;
use App\Modules\Accounting\FixedAsset\Models\DepreciationRun;
use App\Modules\Accounting\FixedAsset\Models\FixedAsset;
use App\Modules\Accounting\FixedAsset\Services\DepreciationCalculator;
use App\Modules\Accounting\FixedAsset\Services\FixedAssetService;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Reports\Support\AnalyticsReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class FixedAssetReportService
{
    public function __construct(
        private readonly FixedAssetService $assets,
        private readonly DepreciationCalculator $calculator,
        private readonly FiscalCalendar $fiscal,
        private readonly ReportBranchScope $branches,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function register(?string $asOf, ?int $branchId): array
    {
        $date = $asOf !== null && $asOf !== '' ? Carbon::parse($asOf)->toDateString() : $this->fiscal->today();
        $month = Carbon::parse($date)->startOfMonth()->toDateString();
        $posted = DepreciationLine::query()
            ->join('fixed_asset_depreciation_runs as runs', 'runs.id', '=', 'fixed_asset_depreciation_lines.run_id')
            ->where('runs.status', DepreciationRunStatus::Posted->value)
            ->whereDate('runs.period_month', '<=', $month)
            ->groupBy('fixed_asset_depreciation_lines.fixed_asset_id')
            ->selectRaw('fixed_asset_depreciation_lines.fixed_asset_id as asset_id, SUM(fixed_asset_depreciation_lines.amount) as total')
            ->pluck('total', 'asset_id');

        $scope = $this->branches->apply($branchId);
        $query = FixedAsset::query()->with(['category:id,name', 'branch:id,name'])
            ->whereDate('acquisition_date', '<=', $date)
            ->where('status', '!=', FixedAssetStatus::Draft->value)
            ->where(function ($query) use ($date): void {
                $query->whereNull('disposed_at')->orWhereDate('disposed_at', '>', $date);
            })
            ->orderBy('asset_number');
        $this->filterBranch($query, 'branch_id', $scope['filters']);

        $rows = [];
        $cost = '0';
        $accumulated = '0';
        $book = '0';
        foreach ($query->get() as $asset) {
            $taken = JournalMath::add((string) $asset->opening_accumulated, (string) ($posted[$asset->id] ?? '0'));
            $value = JournalMath::sub((string) $asset->cost, $taken);
            $rows[] = [
                'asset_number' => (string) ($asset->asset_number ?? ''),
                'asset_name' => (string) $asset->name,
                'asset_category' => (string) ($asset->category->name ?? ''),
                'asset_branch' => (string) ($asset->branch->name ?? ''),
                'in_service_date' => $asset->in_service_date->toDateString(),
                'asset_cost' => JournalMath::store($asset->cost),
                'accumulated' => JournalMath::store($taken),
                'book_value' => JournalMath::store($value),
                'asset_status' => $asset->status->value,
            ];
            $cost = JournalMath::add($cost, (string) $asset->cost);
            $accumulated = JournalMath::add($accumulated, $taken);
            $book = JournalMath::add($book, $value);
        }

        return AnalyticsReport::make(
            [
                ['asset_number', 'text'], ['asset_name', 'text'], ['asset_category', 'text'], ['asset_branch', 'text'],
                ['in_service_date', 'date'], ['asset_cost', 'money'], ['accumulated', 'money'], ['book_value', 'money'], ['asset_status', 'text'],
            ],
            $rows,
            ['asset_cost' => JournalMath::store($cost), 'accumulated' => JournalMath::store($accumulated), 'book_value' => JournalMath::store($book)],
            ['to' => $date, 'branch_id' => $scope['branch_id']],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schedule(?int $branchId, int $months): array
    {
        $months = max(1, min(120, $months > 0 ? $months : 12));
        $scope = $this->branches->apply($branchId);
        $query = FixedAsset::query()->with('category:id,name')->where('status', FixedAssetStatus::Active)->orderBy('asset_number');
        $this->filterBranch($query, 'branch_id', $scope['filters']);
        $rows = [];
        $total = '0';
        foreach ($query->get() as $asset) {
            foreach (array_slice($this->calculator->schedule($this->assets->profile($asset)), 0, $months) as $step) {
                $rows[] = [
                    'asset_number' => (string) ($asset->asset_number ?? ''),
                    'asset_name' => (string) $asset->name,
                    'asset_category' => (string) ($asset->category->name ?? ''),
                    'depreciation_month' => $step['month'],
                    'depreciation_amount' => $step['amount'],
                    'book_value' => $step['book_value_after'],
                ];
                $total = JournalMath::add($total, $step['amount']);
            }
        }

        return AnalyticsReport::make(
            [
                ['asset_number', 'text'], ['asset_name', 'text'], ['asset_category', 'text'],
                ['depreciation_month', 'date'], ['depreciation_amount', 'money'], ['book_value', 'money'],
            ],
            $rows,
            ['depreciation_amount' => JournalMath::store($total)],
            ['branch_id' => $scope['branch_id'], 'months' => $months],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function byPeriod(?string $from, ?string $to, ?int $branchId): array
    {
        $scope = $this->branches->apply($branchId);
        $filters = $scope['filters'];
        $query = DepreciationRun::query()->where('status', DepreciationRunStatus::Posted)->orderBy('period_month');
        if ($from !== null && $from !== '') {
            $query->whereDate('period_month', '>=', Carbon::parse($from)->startOfMonth()->toDateString());
        }
        if ($to !== null && $to !== '') {
            $query->whereDate('period_month', '<=', Carbon::parse($to)->startOfMonth()->toDateString());
        }
        if ($filters !== []) {
            $query->whereHas('lines', fn (Builder $lines) => $this->filterBranch($lines, 'branch_id', $filters));
        }
        $rows = [];
        $total = '0';
        foreach ($query->get() as $run) {
            $amount = (string) $run->total;
            if ($filters !== []) {
                $lines = $run->lines()->getQuery();
                $this->filterBranch($lines, 'branch_id', $filters);
                $amount = (string) $lines->sum('amount');
            }
            $rows[] = [
                'depreciation_month' => $run->period_month->toDateString(),
                'run_number' => (string) ($run->run_number ?? ''),
                'depreciation_amount' => JournalMath::store($amount),
                'automatic' => $run->is_automatic ? 'yes' : 'no',
                'posted_at' => $run->posted_at?->toDateString() ?? '',
            ];
            $total = JournalMath::add($total, $amount);
        }

        return AnalyticsReport::make(
            [
                ['depreciation_month', 'date'], ['run_number', 'text'], ['depreciation_amount', 'money'],
                ['automatic', 'text'], ['posted_at', 'date'],
            ],
            $rows,
            ['depreciation_amount' => JournalMath::store($total)],
            ['from' => $from, 'to' => $to, 'branch_id' => $scope['branch_id']],
        );
    }

    /**
     * Applies the report branch scope: one branch, or the branches the user may see.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters
     */
    private function filterBranch(Builder $query, string $column, array $filters): void
    {
        if (array_key_exists('branch_id', $filters)) {
            $query->where($column, (int) $filters['branch_id']);
        } elseif (array_key_exists('branch_ids', $filters)) {
            $ids = (array) $filters['branch_ids'];
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $ids);
        }
    }
}
