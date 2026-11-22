<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Services;

use App\Modules\Accounting\FixedAsset\Enums\DepreciationRunStatus;
use App\Modules\Accounting\FixedAsset\Enums\FixedAssetStatus;
use App\Modules\Accounting\FixedAsset\Models\DepreciationLine;
use App\Modules\Accounting\FixedAsset\Models\DepreciationRun;
use App\Modules\Accounting\FixedAsset\Models\FixedAsset;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Branch\Models\Branch;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Notification\Services\DomainNotificationPublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Monthly depreciation, one run per branch and month.
 *
 * A user previews, posts and undoes only the branches they may access (ReportBranchScope):
 * a single-branch user works on their branch; a user with several branches may pick one or
 * leave it empty for all of them. The automatic monthly job posts every branch.
 */
class DepreciationRunService
{
    public function __construct(
        private readonly DepreciationCalculator $calculator,
        private readonly FixedAssetService $assets,
        private readonly JournalPostingService $journal,
        private readonly DomainNotificationPublisher $notifications,
        private readonly ReportBranchScope $branchScope,
    ) {}

    /**
     * Run history of the branches the user may see. Only the latest posted run of a branch can be undone.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $query = DepreciationRun::query()->with('branch:id,shortcut_name,name')->orderByDesc('period_month')->orderByDesc('posted_at');
        $this->filterBranch($query, $this->branchScope->apply(null)['filters']);
        $runs = $query->get();

        $latestByBranch = [];
        foreach ($runs as $run) {
            if ($run->status === DepreciationRunStatus::Posted && ! isset($latestByBranch[(int) $run->branch_id])) {
                $latestByBranch[(int) $run->branch_id] = (string) $run->id;
            }
        }

        return $runs
            ->map(fn (DepreciationRun $run): array => $this->present($run, ($latestByBranch[(int) $run->branch_id] ?? null) === (string) $run->id))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(string $month, ?int $branchId = null): array
    {
        $start = $this->monthStart($month);
        $filters = $this->branchScope->apply($branchId)['filters'];
        $this->assertOpen($start);
        $this->assertPreviousPosted($start, $filters);

        $rows = [];
        $byCategory = [];
        foreach ($this->dueAssets($start, $filters) as $built) {
            $asset = $built['asset'];
            $rows[] = [
                'asset_id' => (string) $asset->id,
                'asset_number' => $asset->asset_number,
                'name' => (string) $asset->name,
                'category_id' => (int) $asset->category_id,
                'category_name' => (string) $asset->category?->name,
                'branch_id' => (int) $asset->branch_id,
                'amount' => $built['amount'],
                'book_value_after' => $built['book_value_after'],
            ];
            $key = (int) $asset->category_id;
            $byCategory[$key]['category_id'] = $key;
            $byCategory[$key]['category_name'] = (string) $asset->category?->name;
            $byCategory[$key]['amount'] = JournalMath::store(JournalMath::add($byCategory[$key]['amount'] ?? '0', $built['amount']));
        }

        return [
            'month' => $start,
            'rows' => $rows,
            'totals' => array_values($byCategory),
            'total' => JournalMath::store(array_reduce($rows, fn (string $sum, array $row): string => JournalMath::add($sum, $row['amount']), '0')),
        ];
    }

    /**
     * Posts the month for the requested branch, or for every branch the user may access.
     * Branches already posted for the month are left alone.
     *
     * @return array{runs: list<array<string, mixed>>, total: string}
     */
    public function post(string $month, ?string $userId, ?int $branchId = null, bool $automatic = false): array
    {
        $start = $this->monthStart($month);
        // The automatic job has no user and posts every branch.
        $filters = $automatic ? [] : $this->branchScope->apply($branchId)['filters'];

        $runs = DB::transaction(function () use ($start, $userId, $automatic, $filters): array {
            $this->assertOpen($start);
            $this->assertPreviousPosted($start, $filters);

            /** @var array<int, list<array{asset: FixedAsset, amount: string, book_value_after: string}>> $byBranch */
            $byBranch = [];
            foreach ($this->dueAssets($start, $filters, true) as $built) {
                $byBranch[(int) $built['asset']->branch_id][] = $built;
            }
            $alreadyPosted = false;
            foreach (array_keys($byBranch) as $branch) {
                if ($this->postedRun($start, $branch) !== null) {
                    unset($byBranch[$branch]);
                    $alreadyPosted = true;
                }
            }
            if ($byBranch === []) {
                if ($alreadyPosted || $this->anyPostedRun($start, $filters)) {
                    abort(422, 'Depreciation for this month is already posted.', ['X-Error-Code' => 'DEPRECIATION_ALREADY_POSTED']);
                }
                abort(422, 'Nothing to depreciate for this month.', ['X-Error-Code' => 'DEPRECIATION_NOTHING_DUE']);
            }

            $runs = [];
            ksort($byBranch);
            foreach ($byBranch as $branch => $due) {
                $runs[] = $this->postBranch($start, (int) $branch, $due, $userId, $automatic);
            }

            return $runs;
        });

        $total = '0';
        foreach ($runs as $run) {
            $total = JournalMath::add($total, (string) $run->total);
            $this->notifications->depreciationPosted($run);
        }

        return [
            'runs' => array_map(fn (DepreciationRun $run): array => $this->present($run->load('branch:id,shortcut_name,name'), true), $runs),
            'total' => JournalMath::store($total),
        ];
    }

    /**
     * Undoes the month for one branch. That run must be the branch's latest posted run.
     */
    public function undo(string $month, ?int $branchId, ?string $userId): void
    {
        $filters = $this->branchScope->apply($branchId)['filters'];
        if (! array_key_exists('branch_id', $filters)) {
            abort(422, 'Choose the branch whose depreciation should be undone.', ['X-Error-Code' => 'DEPRECIATION_BRANCH_REQUIRED']);
        }
        $branch = (int) $filters['branch_id'];

        DB::transaction(function () use ($month, $branch, $userId): void {
            $start = $this->monthStart($month);
            $run = DepreciationRun::query()
                ->where('branch_id', $branch)
                ->whereDate('period_month', $start)
                ->where('status', DepreciationRunStatus::Posted)
                ->lockForUpdate()
                ->first();
            if ($run === null) {
                abort(404, 'Depreciation run not found.');
            }
            $latest = DepreciationRun::query()
                ->where('branch_id', $branch)
                ->where('status', DepreciationRunStatus::Posted)
                ->orderByDesc('period_month')
                ->value('id');
            if ((string) $latest !== (string) $run->id) {
                abort(422, 'Only the latest depreciation run of a branch can be undone.', ['X-Error-Code' => 'DEPRECIATION_NOT_LATEST']);
            }
            $this->assertOpen($start);

            $this->journal->reverseDepreciationRun((string) $run->id, $userId);
            $run->load('lines.asset');
            foreach ($run->lines as $line) {
                $asset = $line->asset;
                if ($asset === null || $asset->status === FixedAssetStatus::Disposed) {
                    continue;
                }
                $asset->accumulated_depreciation = JournalMath::store(JournalMath::sub((string) $asset->accumulated_depreciation, (string) $line->amount));
                $asset->depreciated_through = $line->through_before;
                $book = JournalMath::sub((string) $asset->cost, (string) $asset->accumulated_depreciation);
                $asset->status = JournalMath::compare($book, (string) $asset->salvage_value) <= 0
                    ? FixedAssetStatus::FullyDepreciated
                    : FixedAssetStatus::Active;
                $asset->save();
            }
            $run->status = DepreciationRunStatus::Undone;
            $run->save();
        });
    }

    /**
     * Posts the previous month for every branch of the current tenant when automatic depreciation is on.
     * Branches already posted for that month are left alone.
     *
     * @return array{posted: bool, reason: string}
     */
    public function postAutomatic(?string $month = null): array
    {
        if (! (bool) CompanySetting::current()->auto_depreciation) {
            return ['posted' => false, 'reason' => 'disabled'];
        }
        $start = $month !== null
            ? $this->monthStart($month)
            : Carbon::parse(now()->toDateString())->startOfMonth()->subMonth()->toDateString();

        try {
            $this->post($start, null, null, true);
        } catch (HttpException $exception) {
            $code = $exception->getHeaders()['X-Error-Code'] ?? null;
            if ($exception->getStatusCode() === 422 && $code === 'DEPRECIATION_NOTHING_DUE') {
                return ['posted' => false, 'reason' => 'nothing_due'];
            }
            if ($exception->getStatusCode() === 422 && $code === 'DEPRECIATION_ALREADY_POSTED') {
                return ['posted' => false, 'reason' => 'already_posted'];
            }

            throw $exception;
        }

        return ['posted' => true, 'reason' => 'posted'];
    }

    /**
     * @param  list<array{asset: FixedAsset, amount: string, book_value_after: string}>  $due
     */
    private function postBranch(string $start, int $branchId, array $due, ?string $userId, bool $automatic): DepreciationRun
    {
        $total = '0';
        foreach ($due as $built) {
            $total = JournalMath::add($total, $built['amount']);
        }
        $code = Branch::query()->whereKey($branchId)->value('shortcut_name');
        $run = DepreciationRun::query()->create([
            'branch_id' => $branchId,
            'period_month' => $start,
            'run_number' => mb_substr(Carbon::parse($start)->format('Y-m').($code ? '-'.$code : ''), 0, 32),
            'status' => DepreciationRunStatus::Posted,
            'total' => JournalMath::store($total),
            'is_automatic' => $automatic,
            'posted_by' => $userId,
            'posted_at' => now(),
        ]);

        $end = Carbon::parse($start)->endOfMonth()->toDateString();
        /** @var array<int, array<string, array{expense: Account, accumulated: Account, amount: string}>> $splits */
        $splits = [];
        foreach ($due as $built) {
            $asset = $built['asset'];
            $category = $asset->category;
            DepreciationLine::query()->create([
                'run_id' => $run->id,
                'fixed_asset_id' => $asset->id,
                'branch_id' => $branchId,
                'amount' => $built['amount'],
                'book_value_after' => $built['book_value_after'],
                'through_before' => $asset->depreciated_through?->toDateString(),
            ]);
            $asset->accumulated_depreciation = JournalMath::store(JournalMath::add((string) $asset->accumulated_depreciation, $built['amount']));
            $asset->depreciated_through = Carbon::parse($end);
            if (JournalMath::compare($built['book_value_after'], (string) $asset->salvage_value) <= 0) {
                $asset->status = FixedAssetStatus::FullyDepreciated;
            }
            $asset->save();

            $key = (int) $category->expense_account_id.':'.(int) $category->accumulated_account_id;
            $splits[$branchId][$key]['expense'] = $category->expenseAccount;
            $splits[$branchId][$key]['accumulated'] = $category->accumulatedAccount;
            $splits[$branchId][$key]['amount'] = JournalMath::store(JournalMath::add($splits[$branchId][$key]['amount'] ?? '0', $built['amount']));
        }

        $this->journal->depreciationRun($run, $splits, $userId);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DepreciationRun $run, bool $canUndo): array
    {
        return [
            'id' => (string) $run->id,
            'month' => $run->period_month->toDateString(),
            'run_number' => $run->run_number,
            'branch' => $run->branch !== null ? ['id' => (int) $run->branch->id, 'code' => $run->branch->shortcut_name, 'name' => $run->branch->name] : null,
            'branch_id' => (int) $run->branch_id,
            'status' => $run->status->value,
            'total' => JournalMath::store($run->total),
            'is_automatic' => (bool) $run->is_automatic,
            'posted_at' => $run->posted_at?->toIso8601String(),
            'can_undo' => $canUndo && $run->status === DepreciationRunStatus::Posted,
        ];
    }

    /**
     * @param  array<string, mixed>  $branchFilters  empty = every branch
     * @return list<array{asset: FixedAsset, amount: string, book_value_after: string}>
     */
    private function dueAssets(string $monthStart, array $branchFilters, bool $lock = false): array
    {
        $query = FixedAsset::query()
            ->with(['category.expenseAccount', 'category.accumulatedAccount'])
            ->where('status', FixedAssetStatus::Active)
            ->where(function ($query) use ($monthStart): void {
                $query->whereNull('depreciated_through')->orWhereDate('depreciated_through', '<', $monthStart);
            })
            ->orderBy('id');
        $this->filterBranch($query, $branchFilters);
        if ($lock) {
            $query->lockForUpdate();
        }

        $out = [];
        foreach ($query->get() as $asset) {
            $step = $this->calculator->catchUp($this->assets->profile($asset), $monthStart);
            if (! JournalMath::isPositive($step)) {
                continue;
            }
            $book = JournalMath::store(JournalMath::sub(JournalMath::sub((string) $asset->cost, (string) $asset->accumulated_depreciation), $step));
            $out[] = ['asset' => $asset, 'amount' => JournalMath::store($step), 'book_value_after' => $book];
        }

        return $out;
    }

    /**
     * Each branch must post its months in order: a branch with depreciation due for the
     * previous month and no posted run for it has to post that month first.
     *
     * @param  array<string, mixed>  $branchFilters
     */
    private function assertPreviousPosted(string $monthStart, array $branchFilters): void
    {
        $previous = Carbon::parse($monthStart)->subMonth()->startOfMonth()->toDateString();
        $checked = [];
        foreach ($this->dueAssets($previous, $branchFilters) as $built) {
            $branch = (int) $built['asset']->branch_id;
            if (isset($checked[$branch]) || ! JournalMath::isPositive($built['amount'])) {
                continue;
            }
            $checked[$branch] = true;
            if ($this->postedRun($previous, $branch) === null) {
                $name = Branch::query()->whereKey($branch)->value('name');
                abort(422, 'Post depreciation for '.Carbon::parse($previous)->format('Y-m').($name ? ' ('.$name.')' : '').' first.', [
                    'X-Error-Code' => 'DEPRECIATION_PREVIOUS_MONTH_MISSING',
                ]);
            }
        }
    }

    private function assertOpen(string $monthStart): void
    {
        $end = Carbon::parse($monthStart)->endOfMonth()->toDateString();
        $closed = CompanySetting::current()->booksClosedThrough();
        if ($closed !== null && $end <= $closed) {
            abort(422, 'Books are closed through '.$closed.'.', ['X-Error-Code' => 'PERIOD_LOCKED']);
        }
    }

    private function postedRun(string $monthStart, int $branchId): ?DepreciationRun
    {
        return DepreciationRun::query()
            ->where('branch_id', $branchId)
            ->whereDate('period_month', $monthStart)
            ->where('status', DepreciationRunStatus::Posted)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $branchFilters
     */
    private function anyPostedRun(string $monthStart, array $branchFilters): bool
    {
        $query = DepreciationRun::query()->whereDate('period_month', $monthStart)->where('status', DepreciationRunStatus::Posted);
        $this->filterBranch($query, $branchFilters);

        return $query->exists();
    }

    private function monthStart(string $month): string
    {
        return Carbon::parse($month)->startOfMonth()->toDateString();
    }

    /**
     * One branch, or the branches the user may see (ReportBranchScope filters).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters
     */
    private function filterBranch(Builder $query, array $filters): void
    {
        if (array_key_exists('branch_id', $filters)) {
            $query->where('branch_id', (int) $filters['branch_id']);
        } elseif (array_key_exists('branch_ids', $filters)) {
            $ids = (array) $filters['branch_ids'];
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('branch_id', $ids);
        }
    }
}
