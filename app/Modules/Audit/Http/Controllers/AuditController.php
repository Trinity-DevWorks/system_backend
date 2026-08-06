<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Audit;
use App\Models\User;
use App\Modules\Audit\DTOs\AuditResponseData;
use App\Modules\Audit\Http\Requests\IndexAuditRequest;
use App\Modules\Audit\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What: Read-only HTTP API for tenant audit logs (list, show, export).
 * Where: Registered in routes/tenant.php under ensure.module:core + audits permissions.
 * Why: Admins need searchable, immutable history for compliance; no update/delete endpoints.
 */
class AuditController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function index(IndexAuditRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        unset($filters['per_page'], $filters['format']);

        $paginator = $this->auditService->paginate($filters, $perPage);
        $paginator->through(
            fn (Audit $audit): array => AuditResponseData::fromModel($audit)->toArray()
        );

        return ApiResponse::success(
            $paginator->toArray(),
            'Audits fetched successfully.'
        );
    }

    public function show(Audit $audit): JsonResponse
    {
        $audit->loadMissing('user');

        return ApiResponse::success(
            AuditResponseData::fromModel($audit)->toArray(),
            'Audit fetched successfully.'
        );
    }

    public function export(IndexAuditRequest $request): JsonResponse|StreamedResponse
    {
        $filters = $request->validated();
        $format = (string) ($filters['format'] ?? 'json');
        unset($filters['per_page'], $filters['format']);

        $user = $request->user();
        if (! $user instanceof User) {
            return ApiResponse::error('Unauthenticated.', 401, null, [], null, null, 'UNAUTHORIZED');
        }

        $result = $this->auditService->export($filters, $format, $user);

        if ($result instanceof StreamedResponse) {
            return $result;
        }

        return ApiResponse::success($result, 'Audits exported successfully.');
    }
}
