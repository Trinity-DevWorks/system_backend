<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Requests\Central\UpdateCentralMeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Central\CentralProfileService;
use Illuminate\Http\JsonResponse;

class CentralMeController extends Controller
{
    public function __construct(
        private readonly CentralProfileService $profileService,
    ) {}

    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return ApiResponse::success(
            $this->profileService->payload($user),
            'Current user fetched successfully.'
        );
    }

    public function update(UpdateCentralMeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $updated = $this->profileService->updateMe($user, $request->validated());

        return ApiResponse::success(
            $this->profileService->payload($updated),
            'Profile updated successfully.'
        );
    }
}
