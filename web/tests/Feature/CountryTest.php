<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\User;
use App\Services\AiService\PipelinePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CountryTest extends TestCase
{
    use RefreshDatabase;

    private function description(): string
    {
        return str_repeat('Laravel engineer to build REST APIs for payments. ', 2);
    }

    public function test_four_target_markets_are_seeded_and_active(): void
    {
        $this->assertSame(
            ['United Arab Emirates', 'Australia', 'Singapore', 'Thailand', 'Hong Kong', 'Malaysia'],
            Country::active()->pluck('name')->all(),
        );
        $this->assertSame('AE', Country::resolve('Dubai')?->code);
        $this->assertSame('AU', Country::resolve('au')?->code);
        $this->assertSame('MY', Country::resolve('Kuala Lumpur')?->code);
        $this->assertNull(Country::resolve('Vietnam'));
    }

    public function test_job_form_only_accepts_active_countries(): void
    {
        $user = User::factory()->create();
        $uae = Country::where('code', 'AE')->firstOrFail();

        $this->actingAs($user)->post(route('jobs.store'), ['description' => $this->description(), 'country_id' => $uae->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('United Arab Emirates', $user->jobPostings()->first()->country->name);

        $uae->update(['is_active' => false]);
        $this->actingAs($user)->post(route('jobs.store'), ['description' => $this->description().' v2', 'country_id' => $uae->id])
            ->assertSessionHasErrors('country_id');
        $this->actingAs($user)->post(route('jobs.store'), ['description' => $this->description().' v3', 'country_id' => 9999])
            ->assertSessionHasErrors('country_id');
    }

    public function test_import_maps_country_names_codes_and_aliases(): void
    {
        $user = User::factory()->create();
        $d = $this->description();
        $csv = implode("\n", [
            'Title,Company,Description,Country',
            "A,Emaar,\"$d\",Dubai",
            "B,Atlassian,\"$d\",AU",
            "C,Agoda,\"$d\",thailand",
            "D,Grab,\"$d\",",
            "E,Petronas,\"$d\",Vietnam",
        ]);

        $this->actingAs($user)->post(route('jobs.import.store'), ['file' => UploadedFile::fake()->createWithContent('jobs.csv', $csv)])
            ->assertInertiaFlash('importResult.imported', 4)
            ->assertInertiaFlash('importResult.unsupported_countries', ['Vietnam' => [6]]);

        $codes = $user->jobPostings()->with('country')->orderBy('position')->get()->map(fn ($j) => $j->country?->code)->all();
        $this->assertSame(['AE', 'AU', 'TH', null], $codes);
    }

    public function test_ai_service_receives_the_country_name(): void
    {
        $user = User::factory()->create();
        $job = $user->jobPostings()->create([
            'description' => $this->description(), 'country_id' => Country::where('code', 'AE')->value('id'),
        ]);

        $this->assertSame('United Arab Emirates', PipelinePayload::job($job)['country']);
    }
}
