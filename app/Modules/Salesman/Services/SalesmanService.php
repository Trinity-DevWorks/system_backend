<?php

declare(strict_types=1);

namespace App\Modules\Salesman\Services;

use App\Modules\Salesman\DTOs\SalesmanData;
use App\Modules\Salesman\Models\Salesman;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SalesmanService
{
    /**
     * @return Collection<int, Salesman>
     */
    public function list(): Collection
    {
        return Salesman::query()
            ->with(['warehouse:id,name'])
            ->orderBy('full_name')
            ->get();
    }

    public function create(SalesmanData $data): Salesman
    {
        return DB::transaction(function () use ($data): Salesman {
            $salesman = Salesman::query()->create($data->toArray());
            $salesman->load(['warehouse:id,name']);

            return $salesman;
        });
    }

    public function update(Salesman $salesman, SalesmanData $data): Salesman
    {
        return DB::transaction(function () use ($salesman, $data): Salesman {
            $salesman->update($data->toArray());
            $salesman->load(['warehouse:id,name']);

            return $salesman->refresh();
        });
    }

    public function delete(Salesman $salesman): void
    {
        $salesman->delete();
    }
}
