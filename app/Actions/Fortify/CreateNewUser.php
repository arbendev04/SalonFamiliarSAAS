<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user, along with the company
     * that user owns (v1 commercial model: 1 account = 1 company, see
     * ADR-024 in .ai/23-DECISIONS.md).
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'company_name' => ['required', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $company = Company::create([
                'legal_name' => $input['company_name'],
                'status' => 'active',
            ]);

            $ownerRole = Role::query()
                ->whereNull('company_id')
                ->where('name', 'COMPANY_OWNER')
                ->firstOrFail();

            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'role_id' => $ownerRole->id,
                'status' => 'active',
            ]);

            return $user;
        });
    }
}
