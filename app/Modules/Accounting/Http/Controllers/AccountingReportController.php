<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\Http\Requests\AccountingReportRequest;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingReportService;
use App\Modules\Accounting\Support\AccountingReportDocuments;
use App\Support\Export\TabularExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AccountingReportController extends Controller
{
    public function __construct(
        private readonly AccountingReportService $reportService,
        private readonly TabularExportService $tabularExportService,
    ) {}

    public function trialBalance(AccountingReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->trialBalanceData($request), 'Trial balance fetched successfully.');
    }

    public function exportTrialBalance(AccountingReportRequest $request): Response
    {
        app()->setLocale((string) ($request->validated('locale') ?? 'en'));

        return $this->tabularExportService->download(
            AccountingReportDocuments::trialBalance($this->trialBalanceData($request)),
            (string) $request->validated('format'),
        );
    }

    public function accountLedger(AccountingReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->ledgerData($request), 'Account ledger fetched successfully.');
    }

    public function exportAccountLedger(AccountingReportRequest $request): Response
    {
        app()->setLocale((string) ($request->validated('locale') ?? 'en'));

        return $this->tabularExportService->download(
            AccountingReportDocuments::accountLedger($this->ledgerData($request)),
            (string) $request->validated('format'),
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>, from: ?string, to: ?string, level: ?int}
     */
    private function trialBalanceData(AccountingReportRequest $request): array
    {
        $level = $request->validated('level');

        return $this->reportService->trialBalance(
            $request->dateFrom(),
            $request->dateTo(),
            $level !== null ? (int) $level : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerData(AccountingReportRequest $request): array
    {
        $account = Account::query()->findOrFail((int) $request->validated('account_id'));

        return $this->reportService->accountLedger($account, $request->dateFrom(), $request->dateTo());
    }
}
