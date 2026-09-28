<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\InvoiceProof\Http\Requests\InspectWalletRequest;
use App\Modules\InvoiceProof\Services\WalletInspectionService;
use App\Modules\InvoiceProof\Support\BlockchainNetwork;
use Illuminate\Http\JsonResponse;

class WalletInspectionController extends Controller
{
    public function __construct(
        private readonly WalletInspectionService $walletInspectionService,
    ) {}

    public function show(InspectWalletRequest $request): JsonResponse
    {
        $inspection = $this->walletInspectionService->inspect((string) $request->validated('address'));

        return ApiResponse::success(
            [
                'available' => $inspection !== null,
                'blockchain_network' => BlockchainNetwork::key(),
                ...($inspection?->toArray() ?? ['address' => null, 'kind' => null, 'owners' => [], 'threshold' => null]),
            ],
            'Wallet inspected successfully.'
        );
    }
}
