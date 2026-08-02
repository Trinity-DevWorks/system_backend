<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\Models;

use App\Enums\AttachmentViewerCategory;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'company_name',
    'legal_name',
    'phone',
    'email',
    'website',
    'tax_number',
    'registration_number',
    'address',
])]
class CompanyProfile extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    protected $table = 'company_profiles';

    /**
     * Single row per tenant database.
     */
    public static function singleton(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([], [
            'company_name' => (string) (tenant('name') ?: 'Company'),
        ]);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Primary logo image for documents and UI branding.
     *
     * @return MorphOne<Attachment, $this>
     */
    public function logoAttachment(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->where('is_primary', true)
            ->where('viewer_category', AttachmentViewerCategory::Image);
    }
}
