<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Rbac\Http\Requests\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                if (Hash::check($password, (string) $user->getAuthPassword())) {
                    throw new HttpResponseException(
                        ApiResponse::error(
                            'The new password must be different from your current password.',
                            422,
                            null,
                            ['password' => ['The new password must be different from your current password.']],
                            null,
                            null,
                            'PASSWORD_UNCHANGED'
                        )
                    );
                }

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(
                'Unable to reset password. The link may be invalid or expired.',
                422,
                null,
                ['email' => [__($status)]],
                null,
                null,
                'PASSWORD_RESET_FAILED'
            );
        }

        return ApiResponse::success(null, 'Password has been reset successfully.');
    }
}
