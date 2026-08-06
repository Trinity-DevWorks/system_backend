<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Config;
use OwenIt\Auditing\Models\Audit as BaseAudit;
use RuntimeException;

/**
 * What: Application Audit Eloquent model (extends owen-it/laravel-auditing).
 * Where: Registered as `config('audit.implementation')`; used by AuditWriter,
 *        AuditService / AuditController, and morph map key `audit`.
 * Why: Own the model so we can enforce immutability (no Eloquent update/delete)
 *      while still sharing owen-it's schema, casts, and relations. Retention purge
 *      bypasses Eloquent via the query builder in `audits:prune`.
 */
class Audit extends BaseAudit
{
    protected static function booted(): void
    {
        static::updating(function (Model $model): void {
            throw new RuntimeException('Audit records are immutable and cannot be updated.');
        });

        static::deleting(function (Model $model): void {
            throw new RuntimeException(
                'Audit records are immutable. Expired rows must be purged via the audits:prune command.'
            );
        });
    }

    /**
     * Actor who performed the action (morph). Declared here so Larastan sees the relation.
     *
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        $morphPrefix = Config::get('audit.user.morph_prefix', 'user');

        return $this->morphTo(__FUNCTION__, $morphPrefix.'_type', $morphPrefix.'_id');
    }

    /**
     * Subject entity that was changed or referenced.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
