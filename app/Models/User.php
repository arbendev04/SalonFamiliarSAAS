<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Tenancy\CurrentCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The company memberships that belong to this user.
     *
     * @return HasMany<UserCompanyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(UserCompanyMembership::class);
    }

    /**
     * The user's active membership for the current company (see
     * App\Services\Tenancy\CurrentCompany), if any.
     */
    public function currentMembership(): ?UserCompanyMembership
    {
        $companyId = app(CurrentCompany::class)->id();

        if (! $companyId) {
            return null;
        }

        return $this->memberships()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with('role')
            ->first();
    }

    /**
     * Whether the user holds the given atomic permission for the current
     * company. See .ai/06-AUTHORIZATION.md.
     */
    public function hasPermission(string $code): bool
    {
        return $this->currentMembership()?->role->hasPermission($code) ?? false;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
