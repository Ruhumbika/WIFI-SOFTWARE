<?php

namespace App\Http\Middleware;

use App\Models\AdminApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        if (!$plain) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $token = AdminApiToken::with('user')
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        if (!$token || ($token->expires_at && $token->expires_at->isPast())) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('admin_api_token', $token);

        return $next($request);
    }
}
