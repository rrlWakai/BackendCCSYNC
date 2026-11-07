<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $role = $user?->role?->code;

        if ($user === null || ! $user->isActive() || ! in_array($role, $roles, true)) {
            throw new AccessDeniedHttpException('You do not have permission to access this resource.');
        }

        return $next($request);
    }
}
