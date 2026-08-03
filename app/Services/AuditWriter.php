<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Audit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * What: Writes non-Eloquent audit events (auth, download, export) into `audits`.
 * Where: Called from Login/Logout/ResetPassword controllers, AttachmentService downloads,
 *        and AuditService export. Complements owen-it model CRUD auditing.
 * Why: owen-it only hooks Eloquent created/updated/deleted/restored. Enterprise trails
 *      also need security and access events in the same searchable table.
 */
class AuditWriter
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function write(
        string $event,
        Model|Authenticatable|null $auditable = null,
        ?Authenticatable $user = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $tags = null,
    ): ?Audit {
        if (! (bool) config('audit.enabled', true)) {
            return null;
        }

        $actor = $user ?? auth()->user();

        [$auditableType, $auditableId] = $this->resolveAuditable($auditable);

        /** @var class-string<Audit> $implementation */
        $implementation = config('audit.implementation', Audit::class);

        return $implementation::query()->create([
            'user_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
            'user_id' => $actor?->getAuthIdentifier(),
            'event' => $event,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues === [] ? null : $oldValues,
            'new_values' => $newValues === [] ? null : $newValues,
            'url' => Request::fullUrl(),
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 1023),
            'tags' => $tags,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveAuditable(Model|Authenticatable|null $auditable): array
    {
        if ($auditable instanceof Model) {
            return [
                $auditable->getMorphClass(),
                (string) $auditable->getKey(),
            ];
        }

        if ($auditable instanceof Authenticatable) {
            $type = $auditable instanceof Model
                ? $auditable->getMorphClass()
                : 'user';

            return [$type, (string) $auditable->getAuthIdentifier()];
        }

        // Anonymous security events (e.g. login_failed with unknown email) attach to tenant.
        $tenantId = tenant('id');

        return [
            'tenant',
            $tenantId !== null ? (string) $tenantId : 'unknown',
        ];
    }
}
