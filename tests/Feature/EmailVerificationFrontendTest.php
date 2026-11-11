<?php

namespace Tests\Feature;

use Tests\TestCase;

class EmailVerificationFrontendTest extends TestCase
{
    public function test_registration_does_not_create_a_session_and_redirects_to_the_public_verification_page(): void
    {
        $register = file_get_contents(resource_path('js/views/Register.vue'));
        $store = file_get_contents(resource_path('js/stores/auth.js'));
        $registerAction = $this->section($store, 'async register(data)', 'async logout()');

        $this->assertStringContainsString("response.data?.code !== 'email_verification_required'", $registerAction);
        $this->assertStringContainsString('return response.data.code;', $registerAction);
        foreach (['this.token =', 'this.user =', 'localStorage.setItem', 'Authorization'] as $sessionWrite) {
            $this->assertStringNotContainsString($sessionWrite, $registerAction);
        }
        $this->assertStringContainsString("name: 'EmailVerification', query: { status: 'pending' }", $register);
        $this->assertStringNotContainsString('form.value.email', $this->section($register, 'const handleRegister', '</script>'));
        $this->assertStringContainsString('No se pudo completar el registro. Revisa los datos e inténtalo nuevamente.', $register);
    }

    public function test_unverified_login_never_persists_a_session_and_keeps_authentication_errors_local(): void
    {
        $login = file_get_contents(resource_path('js/views/Login.vue'));
        $store = file_get_contents(resource_path('js/stores/auth.js'));
        $api = file_get_contents(resource_path('js/api.js'));
        $loginAction = $this->section($store, 'async login(credentials)', 'async register(data)');
        $loginViewAction = $this->section($login, 'const handleLogin', '</script>');

        $this->assertStringContainsString("requestError?.response?.data?.code === 'email_verification_required'", $loginViewAction);
        $this->assertStringContainsString("name: 'EmailVerification', query: { status: 'pending' }", $loginViewAction);
        $this->assertStringContainsString('Credenciales incorrectas. Revisa tus datos e inténtalo nuevamente.', $loginViewAction);
        $this->assertStringNotContainsString('response.data.message', $loginViewAction);
        $this->assertStringNotContainsString('requestError.response.data.message', $loginViewAction);
        $this->assertStringContainsString("api.post('/auth/login', credentials)", $loginAction);
        $this->assertStringContainsString('this.token = response.data.access_token;', $loginAction);
        $this->assertStringContainsString("error.config?.url !== '/auth/login'", $api);
    }

    public function test_verification_routes_are_public_and_mounting_a_signed_link_does_not_confirm_it(): void
    {
        $router = file_get_contents(resource_path('js/router.js'));
        $view = file_get_contents(resource_path('js/views/EmailVerification.vue'));
        $mount = $this->section($view, 'onMounted(async () => {', 'onBeforeUnmount(() => {');
        $routeWatcher = $this->section($view, 'watch(() => route.fullPath', 'onMounted(async () => {');

        $this->assertStringContainsString("path: '/verificar-correo', name: 'EmailVerification', component: EmailVerification", $router);
        $this->assertStringContainsString("path: '/email/verify/:id/:hash', name: 'EmailVerificationLink', component: EmailVerification", $router);
        $this->assertStringContainsString("['EmailVerification', 'EmailVerificationLink'].includes(to.name)", $router);
        $this->assertStringContainsString("path: '/email/verification'", $router);
        $this->assertStringContainsString("const isConfirmationLink = computed(() => route.name === 'EmailVerificationLink');", $view);
        $this->assertStringContainsString('@click="confirmEmail"', $view);
        $this->assertStringNotContainsString('confirmEmail(', $mount);
        $this->assertStringNotContainsString('fetch(', $mount);
        $this->assertStringNotContainsString('confirmEmail(', $routeWatcher);
        $this->assertStringNotContainsString('fetch(', $routeWatcher);
        $this->assertStringNotContainsString('confirmEmail(', $this->section($view, 'const statusMessage', 'function statusFromRoute()'));
    }

    public function test_confirmation_posts_the_unmodified_signed_url_once_then_replaces_it_with_a_clean_status(): void
    {
        $view = file_get_contents(resource_path('js/views/EmailVerification.vue'));
        $confirmation = $this->section($view, 'async function confirmEmail()', 'async function resendVerification()');
        $resend = $this->section($view, 'async function resendVerification()', 'function invalidatePendingRequests()');

        $this->assertSame(1, preg_match_all('/\bfetch\s*\(/', $view));
        $this->assertSame(1, preg_match_all('/\bfetch\s*\(/', $confirmation));
        $this->assertTrue($this->hasExactlyOneConfirmationPost($confirmation));
        $this->assertSame(1, substr_count($view, '@click="confirmEmail"'));
        $this->assertSame(1, preg_match_all('/\bconfirmEmail\s*\(/', $view), 'Only the function declaration may use call syntax; invocation is the single template click handler.');
        $this->assertStringNotContainsString('confirmEmail', $resend);
        $this->assertStringContainsString('if (confirming.value || !isConfirmationLink.value) return;', $confirmation);
        $this->assertStringContainsString('const signedUrl = window.location.href;', $confirmation);
        $this->assertStringContainsString('fetch(signedUrl, {', $confirmation);
        $this->assertStringContainsString("method: 'POST'", $confirmation);
        $this->assertStringNotContainsString('body:', $confirmation);
        $this->assertStringNotContainsString('URLSearchParams', $confirmation);
        $this->assertStringNotContainsString('signature:', $confirmation);
        $this->assertStringNotContainsString('expires:', $confirmation);
        $this->assertStringContainsString("router.replace({ name: 'EmailVerification', query: { status: safeStatus } })", $view);
        $this->assertStringContainsString(':disabled="confirming"', $view);
        $this->assertStringContainsString('confirmationSequence.value', $confirmation);
        $this->assertStringContainsString('requestIsCurrent(sequence, confirmationSequence)', $confirmation);
    }

    /**
     * @dataProvider additionalConfirmationRequestCases
     */
    public function test_confirmation_request_heuristic_rejects_known_additional_request_channels(string $source): void
    {
        $this->assertFalse($this->hasExactlyOneConfirmationPost($source));
    }

    public static function additionalConfirmationRequestCases(): array
    {
        return [
            'second api post' => ['fetch(signedUrl, { method: \'POST\' }); api.post(\'/email/verify/1/hash\');'],
            'second fetch' => ['fetch(signedUrl, { method: \'POST\' }); fetch(signedUrl, { method: \'POST\' });'],
            'send beacon' => ['fetch(signedUrl, { method: \'POST\' }); navigator.sendBeacon(signedUrl);'],
        ];
    }

    public function test_legacy_invalid_result_route_only_forwards_an_allowlisted_status(): void
    {
        $router = file_get_contents(resource_path('js/router.js'));
        $legacyRoute = $this->section($router, "path: '/email/verification'", "{ path: '/profile'");

        $this->assertTrue($this->hasAllowlistedVerificationRedirect($legacyRoute));
        $this->assertStringContainsString('const result = to.query.result;', $legacyRoute);
        $this->assertStringContainsString("result === 'verified' ? 'verified' : result === 'invalid' ? 'invalid' : 'pending'", $legacyRoute);
        $this->assertStringContainsString("name: 'EmailVerification'", $legacyRoute);
        $this->assertSame(1, preg_match('/query\s*:\s*\{\s*status\s*:/', $legacyRoute));
        $this->assertStringNotContainsString('...to.query', $legacyRoute);
        $this->assertStringNotContainsString('query: to.query', $legacyRoute);
        $this->assertStringNotContainsString('requiresAuth', $legacyRoute);
        $this->assertStringNotContainsString('requiresAdmin', $legacyRoute);
    }

    public function test_both_confirmation_success_codes_replace_the_signed_url_with_verified_status(): void
    {
        $view = file_get_contents(resource_path('js/views/EmailVerification.vue'));
        $confirmation = $this->section($view, 'async function confirmEmail()', 'async function resendVerification()');

        $this->assertStringContainsString("['email_verified', 'email_already_verified'].includes(result?.code)", $confirmation);
        $this->assertStringContainsString("await router.replace({ name: 'EmailVerification', query: { status: safeStatus } });", $view);
        $this->assertStringContainsString("const allowedStatuses = ['verified', 'invalid', 'error'];", $view);
        $this->assertStringNotContainsString('router.replace(window.location', $view);
        $this->assertStringNotContainsString('signature:', $confirmation);
        $this->assertStringNotContainsString('expires:', $confirmation);
    }

    /**
     * @dataProvider unsafeVerificationRedirectCases
     */
    public function test_verification_redirect_heuristic_rejects_query_propagation(string $query): void
    {
        $route = "path: '/email/verification', redirect: (to) => ({ name: 'EmailVerification', query: {$query} })";

        $this->assertFalse($this->hasAllowlistedVerificationRedirect($route));
    }

    public static function unsafeVerificationRedirectCases(): array
    {
        $status = "status: result === 'verified' ? 'verified' : result === 'invalid' ? 'invalid' : 'pending'";
        $prefix = "path: '/email/verification', redirect: (to) => { const result = to.query.result; return { name: 'EmailVerification', query: { ";
        $suffix = ' } }; }';

        return [
            'copy dot property' => [$prefix."{$status}, foo: to.query.foo".$suffix],
            'copy bracket property' => [$prefix."{$status}, foo: to.query['foo']".$suffix],
            'add arbitrary query key' => [$prefix."{$status}, foo: 'bar'".$suffix],
            'spread source query' => [$prefix."{$status}, ...to.query".$suffix],
        ];
    }

    public function test_resend_uses_fixed_messages_and_ignores_remote_payloads(): void
    {
        $view = file_get_contents(resource_path('js/views/EmailVerification.vue'));
        $resend = $this->section($view, 'async function resendVerification()', 'function invalidatePendingRequests()');

        $this->assertSame(1, preg_match_all('/\bapi\.post\s*\(/', $resend));
        $this->assertStringContainsString("api.post('/auth/email/verification-notification', { email }", $resend);
        $this->assertStringContainsString('if (resending.value) return;', $resend);
        $this->assertStringContainsString('response.status === 202', $resend);
        $this->assertStringContainsString('responseStatus === 422', $resend);
        $this->assertStringContainsString('responseStatus === 429', $resend);
        $this->assertStringContainsString('Si la cuenta puede verificarse, enviaremos instrucciones al correo indicado.', $resend);
        foreach (['response.data.message', 'response.data.errors', 'requestError.message', 'console.'] as $remoteOutput) {
            $this->assertStringNotContainsString($remoteOutput, $resend);
        }
        $this->assertStringContainsString('requestIsCurrent(sequence, resendSequence)', $resend);
        $this->assertStringContainsString(':disabled="resending"', $view);
    }

    public function test_email_is_not_persisted_and_pending_requests_are_invalidated_accessibly(): void
    {
        $view = file_get_contents(resource_path('js/views/EmailVerification.vue'));
        $register = file_get_contents(resource_path('js/views/Register.vue'));
        $login = file_get_contents(resource_path('js/views/Login.vue'));

        foreach ([$view, $register, $login] as $source) {
            foreach (['localStorage', 'sessionStorage', 'v-html'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source);
            }
        }
        $this->assertStringContainsString('onBeforeUnmount(() => {', $view);
        $this->assertStringContainsString('invalidatePendingRequests();', $view);
        $this->assertStringContainsString('confirmationController.value?.abort();', $view);
        $this->assertStringContainsString('resendController.value?.abort();', $view);
        $this->assertStringContainsString('watch(() => route.fullPath', $view);
        $this->assertStringContainsString('aria-live="polite"', $view);
        $this->assertStringContainsString('heading.value?.focus();', $view);
        $this->assertStringContainsString('Volver al inicio de sesión', $view);
    }

    private function section(string $source, string $start, string $end): string
    {
        $startAt = strpos($source, $start);
        $this->assertNotFalse($startAt, 'Expected source section was not found.');
        $contentAt = $startAt + strlen($start);
        $endAt = strpos($source, $end, $contentAt);
        $this->assertNotFalse($endAt, 'Expected source section terminator was not found.');

        return substr($source, $contentAt, $endAt - $contentAt);
    }

    /**
     * Textual heuristic for the confirmation request contract. It deliberately
     * does not attempt to resolve aliases or dynamic dispatch like a JS parser.
     */
    private function hasExactlyOneConfirmationPost(string $source): bool
    {
        if (preg_match_all('/\bfetch\s*\(/', $source) !== 1
            || preg_match('/\bfetch\s*\(\s*signedUrl\s*,\s*\{[^}]*\bmethod\s*:\s*[\'\"]POST[\'\"]/s', $source) !== 1) {
            return false;
        }

        $otherRequestPatterns = [
            '/\bapi\s*\.\s*(?:get|post|put|patch|delete|request)\s*\(/i',
            '/\baxios\b/i',
            '/\$http\b/',
            '/\bXMLHttpRequest\b/',
            '/\bnavigator\s*\.\s*sendBeacon\s*\(/',
            '/\bwindow\s*\.\s*fetch\s*\(/',
        ];

        foreach ($otherRequestPatterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Textual heuristic: the destination query must contain only status and
     * the sole source query read must be the exact `to.query.result` allowlist.
     */
    private function hasAllowlistedVerificationRedirect(string $source): bool
    {
        if (substr_count($source, 'to.query') !== 1
            || preg_match('/to\s*\.\s*query\s*\[/', $source) === 1
            || preg_match('/\.\.\.|Object\s*\.\s*assign|URLSearchParams|query\s*:\s*to\s*\.\s*query/i', $source) === 1
            || preg_match('/const\s+result\s*=\s*to\s*\.\s*query\s*\.\s*result\s*;/', $source) !== 1
            || preg_match('/result\s*===\s*[\'\"]verified[\'\"]\s*\?\s*[\'\"]verified[\'\"]\s*:\s*result\s*===\s*[\'\"]invalid[\'\"]\s*\?\s*[\'\"]invalid[\'\"]\s*:\s*[\'\"]pending[\'\"]/', $source) !== 1
            || preg_match('/name\s*:\s*[\'\"]EmailVerification[\'\"]/', $source) !== 1
            || preg_match('/query\s*:\s*\{([^}]*)\}/s', $source, $queryMatch) !== 1) {
            return false;
        }

        if (preg_match_all('/(?:^|,)\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*:/', $queryMatch[1], $keys) !== 1) {
            return false;
        }

        return $keys[1] === ['status'];
    }
}
