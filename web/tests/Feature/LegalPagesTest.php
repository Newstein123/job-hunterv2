<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_privacy_and_terms_are_public_and_show_the_operator(): void
    {
        config(['legal.operator' => 'Example Studio', 'legal.contact_email' => 'privacy@example.com', 'legal.jurisdiction' => null]);

        $this->get(route('privacy'))->assertOk()->assertInertia(fn ($p) => $p->component('legal/privacy')
            ->where('legal.operator', 'Example Studio')->where('legal.contact_email', 'privacy@example.com'));
        $this->get(route('terms'))->assertOk()->assertInertia(fn ($p) => $p->component('legal/terms')
            ->where('legal.jurisdiction', null));
    }
}
