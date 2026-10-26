<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        // Technical webhook failures still reach the exception handler, but
        // must never expose SDK messages, request headers or notification data.
        $this->reportable(function (Throwable $exception) {
            if (app()->bound('request') && request()->is('api/v1/payment/webhook')) {
                \Illuminate\Support\Facades\Log::error('Payment webhook technical failure');
                return false;
            }
        });
        $this->renderable(function (Throwable $exception, \Illuminate\Http\Request $request) {
            if ($request->is('api/v1/payment/webhook') && ! $exception instanceof \Illuminate\Validation\ValidationException) {
                return response()->json(['status' => 'error'], 500);
            }
        });
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
