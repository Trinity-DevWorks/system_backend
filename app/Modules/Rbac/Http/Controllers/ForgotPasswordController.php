<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Rbac\Http\Requests\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User && $user->active) {
            Password::broker()->sendResetLink(['email' => $email]);
        }

        return ApiResponse::success(
            null,
            'If an account exists for that email, a password reset link has been sent.'
        );
    }
}
