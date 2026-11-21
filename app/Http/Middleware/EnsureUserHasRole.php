<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Allow a request only when the signed-in user has one of the given roles.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $userRole = strtolower($user?->role ?? '');
        $allowedRoles = array_map('strtolower', $roles);

        abort_unless($user && in_array($userRole, $allowedRoles, true), 403);
        abort_unless(strtolower((string) ($user->status ?? 'active')) === 'active', 403, 'Account is inactive or deactivated.');

        return $next($request);
    }
}
