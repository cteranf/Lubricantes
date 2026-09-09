<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            return response()->json([
                'message' => 'La cuenta está desactivada.',
                'code' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
