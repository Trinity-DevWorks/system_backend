<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\ScaleBarcode\Services\ScalePluService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScalePluController extends Controller
{
    public function __construct(
        private readonly ScalePluService $plus,
    ) {}

    public function next(): JsonResponse
    {
        return ApiResponse::success($this->plus->next(), 'Next free PLU.');
    }

    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'plu' => ['required', 'string', 'max:20'],
            'item_id' => ['nullable', 'uuid'],
        ]);

        return ApiResponse::success(
            $this->plus->check((string) $request->query('plu'), $request->query('item_id') !== null ? (string) $request->query('item_id') : null),
            'PLU checked.',
        );
    }
}
