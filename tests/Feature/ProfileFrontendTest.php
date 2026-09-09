<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProfileFrontendTest extends TestCase
{
    public function test_profile_has_separate_safe_forms_and_updates_auth_store(): void
    {
        $view = file_get_contents(resource_path('js/views/Profile.vue'));
        $this->assertStringContainsString("api.put('/profile', profile)", $view);
        $this->assertStringContainsString("api.put('/profile/password', password)", $view);
        $this->assertStringContainsString('current-password', $view);
        $this->assertStringContainsString('new-password', $view);
        $this->assertStringContainsString('authStore.user =', $view);
        $this->assertStringNotContainsString('v-html', $view);
        $this->assertStringNotContainsString('localStorage.setItem(\'password\'', $view);
    }
}
