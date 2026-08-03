<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Models\Audit;
use App\Modules\Audit\DTOs\AuditResponseData;
use App\Services\AuditWriter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What: Query, paginate, and export tenant audit rows (read side).
 * Where: Used by AuditController under tenant routes (`/audits`).
 * Why: Isolates filter/export logic from HTTP; export itself is audited via AuditWriter.
 */
class AuditService
{
    public function __construct(
        private readonly AuditWriter $auditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Audit>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->with(['user'])
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Audit>
     */
    public function listForExport(array $filters, int $limit = 5000): Collection
    {
        return $this->filteredQuery($filters)
            ->with(['user'])
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function export(
        array $filters,
        string $format,
        Authenticatable $actor,
    ): StreamedResponse|array {
        $rows = $this->listForExport($filters);

        $this->auditWriter->write(
            event: 'export',
            auditable: $actor instanceof Model ? $actor : null,
            user: $actor,
            newValues: [
                'format' => $format,
                'count' => $rows->count(),
                'filters' => $filters,
            ],
            tags: 'audit,compliance',
        );

        if ($format === 'csv') {
            return $this->streamCsv($rows);
        }

        return $rows
            ->map(fn (Audit $audit): array => AuditResponseData::fromModel($audit)->toArray())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Audit>
     */
    private function filteredQuery(array $filters): Builder
    {
        $query = Audit::query()->orderByDesc('id');

        if (! empty($filters['event'])) {
            $query->where('event', (string) $filters['event']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (string) $filters['user_id']);
        }

        if (! empty($filters['auditable_type'])) {
            $query->where('auditable_type', (string) $filters['auditable_type']);
        }

        if (! empty($filters['auditable_id'])) {
            $query->where('auditable_id', (string) $filters['auditable_id']);
        }

        if (! empty($filters['tags'])) {
            $query->where('tags', 'like', '%'.(string) $filters['tags'].'%');
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * @param  Collection<int, Audit>  $rows
     */
    private function streamCsv(Collection $rows): StreamedResponse
    {
        $filename = 'audits-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'id',
                'event',
                'user_id',
                'auditable_type',
                'auditable_id',
                'old_values',
                'new_values',
                'ip_address',
                'url',
                'tags',
                'created_at',
            ]);

            foreach ($rows as $audit) {
                fputcsv($handle, [
                    $audit->getKey(),
                    $audit->event,
                    $audit->user_id,
                    $audit->auditable_type,
                    $audit->auditable_id,
                    json_encode($audit->old_values ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($audit->new_values ?? [], JSON_UNESCAPED_UNICODE),
                    $audit->ip_address,
                    $audit->url,
                    $audit->tags,
                    (string) $audit->created_at,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
