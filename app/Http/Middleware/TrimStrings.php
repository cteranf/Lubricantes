<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    public function handle($request, \Closure $next)
    {
        // Refund services own normalization; do not strip controls before validation.
        $path = $request->path();
        if ($request->isMethod('POST') && (preg_match('~\Aapi/v1/treasury/orders/[^/]+/refunds\z~', $path) === 1
            || preg_match('~\Aapi/v1/treasury/payment-refunds/[^/]+/complete\z~', $path) === 1)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    /**
     * The names of the attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
    ];
}
