<?php

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('role:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'active' => (bool) $u->active,
                'role' => $u->role ? ['id' => $u->role->id, 'name' => $u->role->name] : null,
                'created_at' => (string) $u->created_at,
            ]);

        return ApiResponse::success($users, 'Users fetched successfully.');
    }
}
