<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\CentralPermissionService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class CentralProfileService
{
    public function __construct(
        private readonly CentralPermissionService $permissionService,
    ) {}

    /**
     * Payload for central `auth/me`: user fields + role + permission matrix.
     *
     * @return array<string, mixed>
     */
    public function payload(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => $user->email !== null ? (string) $user->email : null,
            'is_active' => (bool) $user->is_active,
            'role' => $this->permissionService->resolveRole($user),
            'permissions' => (object) $this->permissionService->matrixForUser($user),
        ];
    }

    /**
     * Self-service profile update (no role / is_active changes).
     *
     * @param  array{name: string, current_password?: string|null, password?: string|null}  $data
     */
    public function updateMe(User $user, array $data): User
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($user, $data): User {
            $payload = ['name' => $data['name']];

            if (! empty($data['password'])) {
                $currentPassword = (string) ($data['current_password'] ?? '');
                if ($currentPassword === '' || ! Hash::check($currentPassword, (string) $user->getAuthPassword())) {
                    throw new HttpResponseException(ApiResponse::error(
                        'The current password is incorrect.',
                        422,
                        null,
                        ['current_password' => ['The current password is incorrect.']],
                        null,
                        null,
                        'CURRENT_PASSWORD_INVALID'
                    ));
                }
                if (Hash::check((string) $data['password'], (string) $user->getAuthPassword())) {
                    throw new HttpResponseException(ApiResponse::error(
                        'The new password must be different from your current password.',
                        422,
                        null,
                        ['password' => ['The new password must be different from your current password.']],
                        null,
                        null,
                        'PASSWORD_UNCHANGED'
                    ));
                }
                $payload['password'] = $data['password'];
            }

            $user->update($payload);

            if (! empty($data['password'])) {
                $user->tokens()->delete();
            }

            return $user->refresh();
        });
    }
}
