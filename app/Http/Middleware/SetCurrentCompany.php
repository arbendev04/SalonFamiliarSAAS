<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the authenticated user's active company for this request. See
 * .ai/15-MULTI-TENANCY.md: the active company is always resolved
 * server-side from the user's own memberships, never trusted from a
 * client-supplied value — including whatever is already in the session.
 */
class SetCurrentCompany
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $memberships = $user->memberships()->where('status', 'active')->with('company')->get();

        $active = $memberships->firstWhere('company_id', $this->currentCompany->id())
            ?? $memberships->first();

        if ($active) {
            $this->currentCompany->set($active->company);
        } else {
            $this->currentCompany->clear();
        }

        return $next($request);
    }
}
