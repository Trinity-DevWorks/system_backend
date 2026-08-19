<?php

namespace App\Modules\Brand\Services;

use App\Modules\Brand\DTOs\BrandData;
use App\Modules\Brand\Models\Brand;
use App\Support\ListPagination;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class BrandService
{
    private const CACHE_LIST = 'brands.list';

    public function list(bool $forceRefresh = false): Collection
    {
        if ($forceRefresh) {
            TenantReferenceCache::forget(self::CACHE_LIST);
        }

        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            Brand::class,
            fn (): Collection => Brand::query()
                ->with('parentBrand')
                ->orderBy('name')
                ->get()
        );
    }

    public function paginateForTable(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = Brand::query()
            ->with('parentBrand')
            ->orderBy('name');

        ListPagination::applySearch(
            $query,
            $search,
            ['code', 'name'],
            ['parentBrand' => ['code', 'name']],
        );

        return $query->paginate($perPage);
    }

    public function create(BrandData $data): Brand
    {
        $model = Brand::query()->create($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST);

        return $model->load('parentBrand');
    }

    public function update(Brand $brand, BrandData $data): Brand
    {
        $brand->update($data->toArray());
        TenantReferenceCache::forget(self::CACHE_LIST);

        return $brand->refresh()->load('parentBrand');
    }

    public function delete(Brand $brand): void
    {
        $brand->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
