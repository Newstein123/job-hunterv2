<?php

namespace Tests\Feature;

use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class JobPostingTest extends TestCase
{
    use RefreshDatabase;

    private function description(): string
    {
        return str_repeat('Senior Laravel engineer to build REST APIs for payments. ', 2);
    }

    public function test_add_job_with_only_a_pasted_description(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('jobs.store'), ['description' => $this->description()]);

        $job = $user->jobPostings()->first();
        $response->assertRedirect(route('jobs.show', $job));
        $this->actingAs($user)->get(route('jobs.show', $job))->assertOk();
    }

    public function test_validates_contact_email_and_rejects_duplicates(): void
    {
        $user = User::factory()->create();
        $existing = JobPosting::factory()->for($user)->create(['url' => 'https://jobs.example/1']);

        $this->actingAs($user)->post(route('jobs.store'), [
            'description' => $this->description(), 'contact_email' => 'not-an-email',
        ])->assertSessionHasErrors('contact_email');

        $this->actingAs($user)->post(route('jobs.store'), [
            'description' => $this->description(), 'url' => 'https://jobs.example/1',
        ])->assertSessionHasErrors(['url' => "You already added this job (#{$existing->id})."]);

        $this->actingAs($user)->post(route('jobs.store'), [
            'description' => $this->description(), 'company' => $existing->company, 'position' => $existing->position,
        ])->assertSessionHasErrors('position');
    }

    public function test_csv_import_skips_duplicates_and_reports_row_errors(): void
    {
        $user = User::factory()->create();
        JobPosting::factory()->for($user)->create(['url' => 'https://jobs.example/existing']);
        $d = $this->description();
        $csv = implode("\n", [
            'Position,Company,Job Description,Job URL,Country,Contact Email',
            "Backend Engineer,Merlion Pay,\"$d\",https://jobs.example/a,Singapore,careers@merlion.example",
            "Frontend Engineer,Orchard,\"$d\",https://jobs.example/b,Singapore,",
            "Dup,Orchard,\"$d\",https://jobs.example/b,Singapore,",
            "Old,Acme,\"$d\",https://jobs.example/existing,,",
            'Bad,Acme,too short,,,bad-email',
            ',,,,,',
        ]);
        $file = UploadedFile::fake()->createWithContent('jobs.csv', $csv);

        $this->actingAs($user)->post(route('jobs.import.store'), ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('importResult.imported', 2)
            ->assertInertiaFlash('importResult.duplicates', [4, 5]);

        $this->assertSame(3, $user->jobPostings()->count());
        $this->assertSame('careers@merlion.example', $user->jobPostings()->where('company', 'Merlion Pay')->value('contact_email'));
    }

    public function test_import_requires_description_column(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('jobs.csv', "Position,Company\nA,B\n");

        $this->actingAs($user)->post(route('jobs.import.store'), ['file' => $file])->assertSessionHasErrors('file');
    }

    public function test_other_users_jobs_are_hidden(): void
    {
        $job = JobPosting::factory()->create();

        $this->actingAs(User::factory()->create())->get(route('jobs.show', $job))->assertNotFound();
    }
}
