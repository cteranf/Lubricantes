<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HasPosCashierAssignment
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $allowed = $user && $user->is_active && $user->role !== 'customer'
            && $user->posCashierAssignments()->where('is_active', true)->exists();
        if (! $allowed) {
            return response()->json(['message' => 'Se requiere una asignación activa de cajero POS.'], 403);
        }

        return $next($request);
    }
}
