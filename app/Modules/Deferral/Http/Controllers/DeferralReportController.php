<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Deferral\Services\DeferralReportService;
use App\Support\Export\TabularDocument;
use App\Support\Export\TabularExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeferralReportController extends Controller
{
    public function __construct(
        private readonly DeferralReportService $reports,
        private readonly TabularExportService $exports,
    ) {}

    public function balances(Request $request): JsonResponse
    {
        return ApiResponse::success($this->balanceData($request), 'Deferral balances fetched successfully.');
    }

    public function exportBalances(Request $request): Response
    {
        app()->setLocale((string) $request->query('locale', 'en'));
        $report = $this->balanceData($request);
        $rows = [];
        foreach ($report['accounts'] as $account) {
            foreach ($account['schedules'] as $schedule) {
                $rows[] = [
                    $account['account']['code'],
                    $account['account']['name'],
                    (string) ($schedule['schedule_number'] ?? ''),
                    (string) $schedule['name'],
                    (string) $schedule['total_amount'],
                    (string) $schedule['recognized_amount'],
                    (string) $schedule['remaining_amount'],
                    (string) $schedule['remaining_amount_primary'],
                ];
            }
            $rows[] = [
                $account['account']['code'],
                $account['account']['name'].' — GL',
                '',
                '',
                '',
                '',
                (string) $account['schedules_remaining'],
                'GL '.$account['gl_balance'].' / diff '.$account['difference'],
            ];
        }

        return $this->exports->download(new TabularDocument(
            title: 'Deferral balances',
            filename: 'deferral-balances',
            headings: ['Account', 'Name', 'Schedule', 'Description', 'Total', 'Recognised', 'Remaining', 'Remaining primary'],
            rows: $rows,
            filters: ['As of '.$report['as_of']],
        ), (string) $request->query('format', 'xlsx'));
    }

    public function forecast(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->reports->forecast(
                $request->query('from') !== null ? (string) $request->query('from') : null,
                $request->query('to') !== null ? (string) $request->query('to') : null,
                $request->query('kind') !== null ? (string) $request->query('kind') : null,
            ),
            'Deferral forecast fetched successfully.'
        );
    }

    /**
     * @return array{as_of: string, accounts: list<array<string, mixed>>}
     */
    private function balanceData(Request $request): array
    {
        return $this->reports->balances(
            $request->query('as_of') !== null ? (string) $request->query('as_of') : null,
            $request->query('kind') !== null ? (string) $request->query('kind') : null,
            $request->query('account_id') !== null ? (int) $request->query('account_id') : null,
        );
    }
}
