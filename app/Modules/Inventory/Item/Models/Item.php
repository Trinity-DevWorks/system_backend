<?php

namespace App\Modules\Inventory\Item\Models;

use App\Enums\AttachmentViewerCategory;
use App\Models\Attachment;
use App\Modules\Brand\Models\Brand;
use App\Modules\Category\Models\Category;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Supplier\Models\SupplierItem;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'name',
    'sku',
    'plu_code',
    'item_type_id',
    'category_id',
    'brand_id',
    'base_uom_id',
    'vat_group_id',
    'description',
    'track_inventory',
    'allow_sale',
    'allow_purchase',
    'is_active',
])]
class Item extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'track_inventory' => 'boolean',
            'allow_sale' => 'boolean',
            'allow_purchase' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<UnitOfMeasurement, $this>
     */
    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'base_uom_id');
    }

    /**
     * @return HasMany<ItemUom, $this>
     */
    public function itemUoms(): HasMany
    {
        return $this->hasMany(ItemUom::class);
    }

    /**
     * @return HasMany<ItemBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class);
    }

    public function supplierItems(): HasMany
    {
        return $this->hasMany(SupplierItem::class);
    }

    public function vatGroup(): BelongsTo
    {
        return $this->belongsTo(VatGroup::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Primary catalog image for list/detail views.
     *
     * @return MorphOne<Attachment, $this>
     */
    public function primaryImageAttachment(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->where('is_primary', true)
            ->where('viewer_category', AttachmentViewerCategory::Image);
    }

    /**
     * @return HasMany<BundleItem, $this>
     */
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(BundleItem::class, 'bundle_item_id');
    }

    /**
     * @return HasOne<Recipe, $this>
     */
    public function recipe(): HasOne
    {
        return $this->hasOne(Recipe::class);
    }
}
