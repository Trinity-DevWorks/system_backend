<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Tenant print preferences (singleton).
 *
 * @property int $id
 * @property string $paper a4 | a5 | letter
 * @property bool $show_logo
 * @property bool $show_tax_number
 * @property bool $show_address
 * @property bool $show_signatures
 * @property string|null $footer_text
 * @property string|null $terms_text
 */
#[Fillable(['paper', 'show_logo', 'show_tax_number', 'show_address', 'show_signatures', 'footer_text', 'terms_text'])]
class PrintSetting extends Model implements AuditableContract
{
    use Auditable;

    public const CACHE_KEY = 'print_settings.singleton';

    public const PAPERS = ['a4', 'a5', 'letter'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_logo' => 'boolean',
            'show_tax_number' => 'boolean',
            'show_address' => 'boolean',
            'show_signatures' => 'boolean',
        ];
    }

    public static function singleton(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([], [
            'paper' => 'a4',
            'show_logo' => true,
            'show_tax_number' => true,
            'show_address' => true,
            'show_signatures' => true,
        ]);
    }

    /** Cached read for PDF rendering. */
    public static function current(): self
    {
        /** @var self */
        return TenantReferenceCache::rememberModel(self::CACHE_KEY, self::class, fn (): self => self::singleton());
    }

    public static function forgetCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
