<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMockPaymentEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = app()->environment(['local', 'testing']) && (bool) config('payment.mock.enabled', false);
        abort_unless($allowed, 404);

        return $next($request);
    }
}
