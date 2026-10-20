<?php

namespace Tests\Feature;

use App\Models\CareerProfile;
use App\Models\Country;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\AiService\PipelinePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CareerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_and_update_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('career-profile.update'), [
            'full_name' => 'Alex Tan', 'email' => 'alex@example.com', 'languages' => 'English, Mandarin, English',
        ])->assertRedirect();

        $this->assertSame(['English', 'Mandarin'], $user->careerProfile->languages);
        $this->actingAs($user)->get(route('career-profile.edit'))->assertOk();
    }

    public function test_location_availability_and_target_countries(): void
    {
        $user = User::factory()->create();
        $sg = Country::resolve('Singapore');
        $th = Country::resolve('Thailand');
        $ae = Country::resolve('UAE');

        $this->actingAs($user)->put(route('career-profile.update'), [
            'full_name' => 'Alex Tan', 'email' => 'alex@example.com',
            'location' => 'Bangkok, Thailand', 'availability' => 'Immediately',
            'target_country_ids' => [$sg->id, $th->id],
        ])->assertSessionHasNoErrors();

        $profile = $user->careerProfile;
        $this->assertSame('Immediately', $profile->availability);
        $this->assertEqualsCanonicalizing([$sg->id, $th->id], $profile->targetCountries->modelKeys());
        $this->assertSame('Immediately', PipelinePayload::profile($profile)['availability']);
        $this->assertSame('Bangkok, Thailand', PipelinePayload::profile($profile)['location']);

        $uaeJob = JobPosting::factory()->for($user)->create(['country_id' => $ae->id]);
        $sgJob = JobPosting::factory()->for($user)->create(['country_id' => $sg->id]);
        $this->actingAs($user)->get(route('jobs.show', $uaeJob))->assertInertia(fn ($p) => $p->where('outsideTargets', true));
        $this->actingAs($user)->get(route('jobs.show', $sgJob))->assertInertia(fn ($p) => $p->where('outsideTargets', false));

        // Clearing targets means every country is fine again.
        $this->actingAs($user)->put(route('career-profile.update'), ['full_name' => 'Alex Tan', 'email' => 'alex@example.com']);
        $this->actingAs($user)->get(route('jobs.index'))->assertInertia(fn ($p) => $p->where('jobs.data.0.outside_targets', false));
    }

    public function test_target_countries_must_be_supported(): void
    {
        $this->actingAs(User::factory()->create())->put(route('career-profile.update'), [
            'full_name' => 'Alex Tan', 'email' => 'alex@example.com', 'target_country_ids' => [999999],
        ])->assertSessionHasErrors('target_country_ids.0');
    }

    public function test_import_replaces_profile_from_ai_service_json(): void
    {
        $user = User::factory()->create();
        CareerProfile::factory()->for($user)->create();
        $file = UploadedFile::fake()->createWithContent('candidate_profile.json',
            file_get_contents(base_path('tests/Fixtures/candidate_profile.json')));

        $this->actingAs($user)->post(route('career-profile.import'), ['file' => $file])->assertSessionHasNoErrors();

        $profile = $user->fresh()->careerProfile;
        $this->assertSame('Alex Tan', $profile->full_name);
        $this->assertCount(11, $profile->skills);
        $this->assertCount(2, $profile->experiences);
        $this->assertSame(['PHP', 'Laravel', 'MySQL', 'REST API', 'Docker', 'Redis', 'AWS'], $profile->experiences->first()->skills);
        $this->assertCount(2, $profile->projects);
        $this->assertCount(1, $profile->education);
    }

    public function test_import_rejects_invalid_json(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('p.json', '{"name": ');

        $this->actingAs($user)->post(route('career-profile.import'), ['file' => $file])->assertSessionHasErrors('file');
    }

    public function test_section_items_crud_and_parsing(): void
    {
        $user = User::factory()->create();
        $profile = CareerProfile::factory()->for($user)->create();

        $this->actingAs($user)->post(route('career-items.store', 'experiences'), [
            'title' => 'Lead', 'company' => 'Globex', 'start_date' => '2019-01-01',
            'achievements' => "Built X\n\n  Led Y  ", 'skills' => 'Go, Docker, ,Go',
        ])->assertSessionHasNoErrors();

        $exp = $profile->experiences()->where('company', 'Globex')->first();
        $this->assertSame(['Built X', 'Led Y'], $exp->achievements);
        $this->assertSame(['Go', 'Docker'], $exp->skills);

        $this->actingAs($user)->post(route('career-items.store', 'skills'), ['name' => 'PHP'])
            ->assertSessionHasErrors('name'); // duplicate of factory skill

        $this->actingAs($user)->delete(route('career-items.destroy', ['experiences', $exp->id]))->assertRedirect();
        $this->assertModelMissing($exp);
    }

    public function test_users_cannot_touch_other_users_items(): void
    {
        $owner = CareerProfile::factory()->create();
        $exp = $owner->experiences()->first();
        $intruder = User::factory()->create();
        CareerProfile::factory()->for($intruder)->create();

        $this->actingAs($intruder)->delete(route('career-items.destroy', ['experiences', $exp->id]))->assertNotFound();
        $this->assertModelExists($exp);
    }
}
