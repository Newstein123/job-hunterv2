<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_filled_rules_are_stored_with_types(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('rules.update'), [
            'email_rules' => ['max_words' => '180', 'include_salary' => '0', 'include_linkedin' => '', 'tone' => ''],
            'application_rules' => [
                'skills_to_emphasize' => 'Laravel, PHP', 'claims_to_avoid' => "rockstar\n\nninja", 'cover_letter' => 'always',
            ],
            'sending_rules' => ['timezone' => 'Asia/Singapore'], // removed section: ignored
        ])->assertSessionHasNoErrors();

        $this->assertEquals([
            'email_rules' => ['max_words' => 180, 'include_salary' => false],
            'application_rules' => ['cover_letter' => 'always', 'skills_to_emphasize' => ['Laravel', 'PHP'],
                'claims_to_avoid' => ['rockstar', 'ninja']],
        ], $user->applicationRule->rules);
    }

    public function test_invalid_rules_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('rules.update'), [
            'email_rules' => ['max_words' => 5],
            'cv_rules' => ['preferred_formats' => ['odt'], 'max_pages' => 0],
        ])->assertSessionHasErrors([
            'email_rules.max_words', 'cv_rules.preferred_formats.0', 'cv_rules.max_pages',
        ]);
    }
}
