<?php

namespace App\Services\Tenancy;

use App\Models\Company;
use Illuminate\Support\Facades\Session;

/**
 * Resolves the tenant ("empresa activa") for the current request. See
 * .ai/15-MULTI-TENANCY.md — the active company is always resolved
 * server-side from the authenticated user's membership, never from a
 * client-supplied value.
 */
class CurrentCompany
{
    private const SESSION_KEY = 'current_company_id';

    private ?Company $resolved = null;

    public function set(Company $company): void
    {
        $this->resolved = $company;
        Session::put(self::SESSION_KEY, $company->id);
    }

    public function clear(): void
    {
        $this->resolved = null;
        Session::forget(self::SESSION_KEY);
    }

    public function id(): ?string
    {
        if ($this->resolved) {
            return $this->resolved->id;
        }

        return Session::get(self::SESSION_KEY);
    }

    public function company(): ?Company
    {
        if ($this->resolved) {
            return $this->resolved;
        }

        $id = Session::get(self::SESSION_KEY);

        return $id ? Company::query()->whereKey($id)->first() : null;
    }
}
