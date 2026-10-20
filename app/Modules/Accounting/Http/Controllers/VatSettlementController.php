<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\Http\Requests\PreviewVatSettlementRequest;
use App\Modules\Accounting\Http\Requests\StoreVatSettlementRequest;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\VatSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VatSettlementController extends Controller
{
    public function __construct(
        private readonly VatSettlementService $settlements,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->settlements->list(), 'VAT settlements fetched successfully.');
    }

    public function preview(PreviewVatSettlementRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->settlements->preview((string) $request->validated('period_to')),
            'VAT settlement preview fetched successfully.',
        );
    }

    public function store(StoreVatSettlementRequest $request): JsonResponse
    {
        $entry = $this->settlements->settle(
            (string) $request->validated('period_to'),
            (string) $request->validated('entry_date'),
            $this->userId($request),
        );

        return ApiResponse::success(
            ['id' => (string) $entry->id, 'entry_number' => (string) $entry->entry_number],
            'VAT settlement posted successfully.',
        );
    }

    public function reverse(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $reversal = $this->settlements->reverse($journalEntry, $this->userId($request));

        return ApiResponse::success(
            ['id' => (string) $reversal->id, 'entry_number' => (string) $reversal->entry_number],
            'VAT settlement reversed successfully.',
        );
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->id;

        return $id !== null ? (string) $id : null;
    }
}
