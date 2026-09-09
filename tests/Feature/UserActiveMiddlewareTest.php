<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class UserActiveMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_request_is_left_for_authentication_layer(): void
    {
        $request = Request::create('/api/v1/profile');
        $nextCalled = false;
        $response = app(EnsureUserIsActive::class)->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return new Response('next');
        });

        $this->assertTrue($nextCalled);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_inactive_user_is_rejected_before_controller_callback_runs(): void
    {
        $request = Request::create('/api/v1/profile');
        $request->setUserResolver(fn () => User::factory()->make(['is_active' => false]));
        $nextCalled = false;
        $response = app(EnsureUserIsActive::class)->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return new Response('next');
        });

        $this->assertFalse($nextCalled);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('account_inactive', json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['code']);
    }
}
