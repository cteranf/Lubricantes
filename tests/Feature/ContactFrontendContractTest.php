<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContactFrontendContractTest extends TestCase
{
    public function test_contact_autofill_preserves_visitor_edits_and_cleans_up_without_persisting_personal_data(): void
    {
        $view = file_get_contents(resource_path('js/views/Contact.vue'));
        $authStore = file_get_contents(resource_path('js/stores/auth.js'));
        $submitStart = strpos($view, 'async function submitForm()');
        $submitEnd = strpos($view, 'onBeforeUnmount(() => {', $submitStart);
        $submit = substr($view, $submitStart, $submitEnd - $submitStart);
        preg_match('/\bwatch\s*\(\s*(\(\s*\)\s*=>\s*\[[\s\S]*?\])\s*,/u', $view, $watchSourceMatches);
        $watchSource = $watchSourceMatches[1] ?? '';

        foreach ([
            "import { useAuthStore } from '@/stores/auth';",
            'const authStore = useAuthStore();',
            'watch(',
            'if (!token)',
            'if (user) applyProfileContact(user)',
            "form[field] === ''",
            '!touchedContactFields[field]',
            "@input=\"markContactFieldTouched('name')\"",
            "@input=\"markContactFieldTouched('email')\"",
            "@input=\"markContactFieldTouched('phone')\"",
            'autocomplete="name"',
            'autocomplete="email"',
            'autocomplete="tel"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $view, "Contact autofill contract missing: {$expected}");
        }
        $this->assertNotSame('', $watchSource, 'Contact auth watch must use a function getter as its source.');
        $this->assertMatchesRegularExpression('/^\(\s*\)\s*=>\s*\[\s*authStore\.token\s*,\s*authStore\.user\s*\]$/u', $watchSource);

        $this->assertSame(1, substr_count($submit, "api.post('/contact-inquiries'"));
        $this->assertStringNotContainsString('user_id', $submit);
        $this->assertStringContainsString("form.subject = ''", $submit);
        $this->assertStringContainsString("form.message = ''", $submit);
        $this->assertStringNotContainsString("form.name = ''", $submit);
        $this->assertStringNotContainsString("form.email = ''", $submit);
        $this->assertStringNotContainsString("form.phone = ''", $submit);
        $this->assertStringNotContainsString('data.message', $submit);
        $this->assertStringNotContainsString('error.response.data.errors', $submit);
        $this->assertStringContainsString('fieldMessages[field]', $submit);

        foreach (['contextController?.abort()', 'submissionController?.abort()', 'stopAuthWatch()', 'mounted = false', 'submissionSequence++'] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }
        $this->assertStringContainsString('this.user = response.data.user', $authStore);
        $this->assertStringContainsString('this.user = response.data.data || response.data', $authStore);
        foreach (['localStorage', 'sessionStorage', 'v-html', 'console.', 'window.location', 'router.push'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }

        $this->assertStringContainsString("'phone' => \$this->phone", file_get_contents(app_path('Http/Resources/UserResource.php')));
    }
}
