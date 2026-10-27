<?php

namespace Tests\Feature;

use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use App\Services\Mail\RecipientValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The downloadable samples on the import page must import cleanly as shipped. */
class SampleImportFilesTest extends TestCase
{
    use RefreshDatabase;

    private function sample(string $name): UploadedFile
    {
        return new UploadedFile(public_path("samples/{$name}"), $name, 'text/csv', null, true);
    }

    public function test_company_list_sample_imports_every_row_and_flags_weak_inboxes(): void
    {
        Storage::fake(Resume::disk());
        Queue::fake();
        $user = User::factory()->create();
        CareerProfile::factory()->for($user)->create();
        $resume = Resume::factory()->withFile()->for($user)->create();

        $this->actingAs($user)->post(route('jobs.import.store'), [
            'file' => $this->sample('company-list-sample.csv'), 'kind' => 'speculative',
            'position' => 'Operations Executive', 'resume_id' => $resume->id,
        ])->assertSessionHasNoErrors()
            ->assertInertiaFlash('importResult.imported', 8)
            ->assertInertiaFlash('importResult.errors', [])
            ->assertInertiaFlash('importResult.unsupported_countries', []);

        $flags = $user->jobPostings()->pluck('contact_flag', 'contact_email');
        $this->assertSame(JobPosting::CONTACT_HIRING, $flags['careers@brightpath-logistics.example']);
        $this->assertSame(JobPosting::CONTACT_OTHER_TEAM, $flags['sales@merlion-print.example']);
        $this->assertSame(JobPosting::CONTACT_PERSON, $flags['somchai.k@chaophraya-foods.example']);
        $this->assertSame(8, $user->jobPostings()->whereNotNull('country_id')->count());

        // The sample addresses can never actually be emailed.
        $this->assertNotSame([], app(RecipientValidator::class)->problemsForAll(['careers@brightpath-logistics.example'], $user->id));
    }

    public function test_job_posts_sample_imports_every_row(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('jobs.import.store'), ['file' => $this->sample('job-posts-sample.csv')])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('importResult.imported', 3)
            ->assertInertiaFlash('importResult.errors', []);
        $this->assertSame(3, $user->jobPostings()->whereNotNull('country_id')->whereNotNull('contact_email')->count());
    }
}
