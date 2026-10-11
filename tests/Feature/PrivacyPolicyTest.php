<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrivacyPolicyTest extends TestCase
{
    public function test_it_is_public_and_in_english_by_default(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Privacy Policy')
            ->assertSee('What we never do')
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_it_follows_the_lang_query(): void
    {
        $this->get('/privacy?lang=fr')
            ->assertOk()
            ->assertSee('<html lang="fr">', false)
            ->assertSee('Politique de confidentialité');
    }

    public function test_it_follows_the_app_language_header(): void
    {
        $this->get('/privacy', ['Accept-Language' => 'fr-FR'])
            ->assertOk()
            ->assertSee('Ce que nous ne faisons jamais');
    }

    public function test_it_shows_the_contact_address_when_one_is_set(): void
    {
        config(['app.privacy_contact_email' => 'privacy@example.org']);

        $this->get('/privacy')->assertSee('mailto:privacy@example.org', false);
    }

    public function test_it_points_to_help_and_support_without_a_contact_address(): void
    {
        config(['app.privacy_contact_email' => null]);

        $this->get('/privacy')
            ->assertDontSee('mailto:', false)
            ->assertSee('Help &amp; Support', false);
    }
}
