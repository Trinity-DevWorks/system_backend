<?php

namespace App\Models;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Models\BranchUser;
use App\Modules\Rbac\Models\Role;
use App\Modules\Salesman\Models\Salesman;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable(['name', 'email', 'password', 'active', 'created_by'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements AuditableContract, CanResetPasswordContract
{
    use Auditable;
    use CanResetPassword;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;

    /**
     * Never persist secrets into the audits table (owen-it local exclude wins over config).
     *
     * @var list<string>
     */
    protected array $auditExclude = [
        'password',
        'remember_token',
    ];

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<Salesman, $this>
     */
    public function salesmanProfile(): HasOne
    {
        return $this->hasOne(Salesman::class);
    }

    /**
     * Branch memberships with the role assigned in each branch.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user')
            ->using(BranchUser::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * Roles assigned across branches (may include the same role more than once).
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'branch_user')
            ->using(BranchUser::class)
            ->withPivot('branch_id')
            ->withTimestamps();
    }

    public function roleIdForBranch(int $branchId): ?int
    {
        $this->loadMissing('branches');

        $branch = $this->branches->first(
            fn (Branch $b): bool => (int) $b->id === $branchId
        );

        if ($branch === null || $branch->pivot?->role_id === null) {
            return null;
        }

        return (int) $branch->pivot->role_id;
    }
}
