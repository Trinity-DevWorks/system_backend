<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\DocumentType;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * How printed documents (PDFs) look, company-wide.
 * The row with no document type is the default; a row per document type overrides it for that type.
 *
 * @property int $id
 * @property string|null $document_type null = company default
 * @property string $paper a4 | a5 | letter | roll80 | roll58
 * @property bool $show_logo
 * @property bool $show_tax_number
 * @property bool $show_address
 * @property bool $show_signatures
 * @property string|null $footer_text
 * @property string|null $terms_text
 */
#[Fillable(['document_type', 'paper', 'show_logo', 'show_tax_number', 'show_address', 'show_signatures', 'footer_text', 'terms_text'])]
class PrintSetting extends Model implements AuditableContract
{
    use Auditable;

    public const CACHE_KEY = 'print_settings.all';

    /** Full-page papers. */
    public const PAGE_PAPERS = ['a4', 'a5', 'letter'];

    /** Thermal rolls: only for document types with a receipt layout. */
    public const ROLL_PAPERS = ['roll80', 'roll58'];

    public const PAPERS = ['a4', 'a5', 'letter', 'roll80', 'roll58'];

    /** Document types that print a PDF, so they can have their own settings. */
    public const PRINTABLE_TYPES = [
        DocumentType::SalesQuotation,
        DocumentType::SalesOrder,
        DocumentType::DeliveryNote,
        DocumentType::SalesInvoice,
        DocumentType::SalesCreditNote,
        DocumentType::CustomerReceipt,
        DocumentType::CustomerRefund,
        DocumentType::PurchaseOrder,
        DocumentType::GoodsReceipt,
        DocumentType::LandedCost,
        DocumentType::PurchaseInvoice,
        DocumentType::PurchaseDebitNote,
        DocumentType::SupplierPayment,
        DocumentType::SupplierRefund,
        DocumentType::StockTransfer,
        DocumentType::JournalEntry,
        DocumentType::FixedAsset,
    ];

    /** Document types with a thermal receipt layout. */
    public const RECEIPT_TYPES = [DocumentType::SalesInvoice, DocumentType::CustomerReceipt];

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

    /** The company default row (created on first use). */
    public static function singleton(): self
    {
        /** @var self */
        return static::query()->whereNull('document_type')->firstOrCreate([], [
            'document_type' => null,
            'paper' => 'a4',
            'show_logo' => true,
            'show_tax_number' => true,
            'show_address' => true,
            'show_signatures' => true,
        ]);
    }

    /** Cached read for PDF rendering: the type's own settings, else the company default. */
    public static function current(?DocumentType $type = null): self
    {
        /** @var Collection<int, self> $all */
        $all = TenantReferenceCache::rememberModels(self::CACHE_KEY, self::class, function (): Collection {
            self::singleton();

            return static::query()->get();
        });

        $own = $type !== null ? $all->firstWhere('document_type', $type->value) : null;
        if ($own instanceof self) {
            return $own;
        }
        $default = $all->first(fn (self $row): bool => $row->document_type === null);

        return $default instanceof self ? $default : self::singleton();
    }

    public static function printable(string $type): ?DocumentType
    {
        $case = DocumentType::tryFrom($type);

        return $case !== null && in_array($case, self::PRINTABLE_TYPES, true) ? $case : null;
    }

    public static function hasReceipt(DocumentType $type): bool
    {
        return in_array($type, self::RECEIPT_TYPES, true);
    }

    public function isRoll(): bool
    {
        return in_array($this->paper, self::ROLL_PAPERS, true);
    }

    public static function forgetCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
