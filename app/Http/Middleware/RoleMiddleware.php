<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 403, 'Your account is inactive.');
        if ($permissions) abort_unless(collect($permissions)->contains(fn ($permission) => $user->hasPermission($permission)), 403);
        return $next($request);
    }
}
