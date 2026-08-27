<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserCompanyMembership>
 */
class UserCompanyMembershipFactory extends Factory
{
    protected $model = UserCompanyMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'role_id' => Role::factory(),
            'status' => 'active',
        ];
    }
}
