<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class RoleMiddleware
{
    public function handle(Request $request, Closure $next, $role)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!Auth::user()->hasRole($role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
