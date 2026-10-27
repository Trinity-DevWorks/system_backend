<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\DTOs\JournalEntryData;
use App\Modules\Accounting\DTOs\JournalEntryResponseData;
use App\Modules\Accounting\Http\Requests\ReplaceJournalEntryLinesRequest;
use App\Modules\Accounting\Http\Requests\StoreJournalEntryRequest;
use App\Modules\Accounting\Http\Requests\UpdateJournalEntryRequest;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryPdfService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Support\SourceDocuments;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JournalEntryController extends Controller
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly JournalEntryPdfService $journalEntryPdfService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->journalEntryService->paginate([
            'search' => ListPagination::search($request),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'source_type' => $request->query('source_type'),
            'status' => $request->query('status'),
            'account_id' => $request->query('account_id') !== null ? (int) $request->query('account_id') : null,
        ], ListPagination::perPage($request));

        $numbers = SourceDocuments::numbers(collect($paginator->items())
            ->map(fn (JournalEntry $e): array => [(string) $e->source_type, $e->isManual() ? null : $e->source_id]));

        return ListPagination::json(
            $paginator,
            fn (JournalEntry $entry): array => JournalEntryResponseData::summary(
                $entry,
                $numbers[$entry->source_type.':'.$entry->source_id] ?? null,
            ),
            'Journal entries fetched successfully.'
        );
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $entry = $this->journalEntryService->createManual(
            JournalEntryData::fromRequest($request),
            $this->userId($request),
        );

        return ApiResponse::created(JournalEntryResponseData::detail($entry), 'Journal entry created successfully.');
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        return ApiResponse::success(
            JournalEntryResponseData::detail($this->journalEntryService->load($journalEntry)),
            'Journal entry fetched successfully.'
        );
    }

    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->journalEntryService->updateManual($journalEntry, JournalEntryData::fromRequest($request));

        return ApiResponse::success(JournalEntryResponseData::detail($entry), 'Journal entry updated successfully.');
    }

    public function replaceLines(ReplaceJournalEntryLinesRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->journalEntryService->replaceLines($journalEntry, $request->lines());

        return ApiResponse::success(JournalEntryResponseData::detail($entry), 'Journal entry lines saved successfully.');
    }

    public function post(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->journalEntryService->post($journalEntry, $this->userId($request));

        return ApiResponse::success(JournalEntryResponseData::detail($entry), 'Journal entry posted successfully.');
    }

    public function reverse(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $reversal = $this->journalEntryService->reverse($journalEntry, $this->userId($request));

        return ApiResponse::success(JournalEntryResponseData::detail($reversal), 'Journal entry reversed successfully.');
    }

    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        $this->journalEntryService->delete($journalEntry);

        return ApiResponse::success(null, 'Journal entry deleted successfully.');
    }

    public function pdf(Request $request, JournalEntry $journalEntry): Response
    {
        $pdf = $this->journalEntryPdfService->render(
            $journalEntry,
            $request->query('locale')
        );

        return $pdf->download($this->journalEntryPdfService->downloadFilename($journalEntry));
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->id;

        return $id !== null ? (string) $id : null;
    }
}
