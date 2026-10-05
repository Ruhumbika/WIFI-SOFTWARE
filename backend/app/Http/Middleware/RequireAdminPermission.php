<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (!$user || !$user->is_active || ($permission === 'super' ? !$user->is_super_user : !$user->hasAdminPermission($permission))) {
            return response()->json(['message' => 'Access denied'], 403);
        }
        return $next($request);
    }
}
