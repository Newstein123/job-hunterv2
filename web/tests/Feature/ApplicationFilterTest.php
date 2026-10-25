<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\Country;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::disk());
        $this->user = User::factory()->create();
        CareerProfile::factory()->for($this->user)->create();
        Resume::factory()->withFile()->for($this->user)->create();
    }

    private function application(string $position, ApplicationStatus $status = ApplicationStatus::Approved, array $job = []): Application
    {
        $job = JobPosting::factory()->for($this->user)->create([
            'position' => $position, 'company' => "{$position} Co", 'contact_email' => 'jobs@acme-careers.com',
            'country_id' => Country::where('code', 'SG')->value('id'), ...$job,
        ]);
        $app = Application::factory()->create([
            'job_id' => $job->id, 'user_id' => $this->user->id, 'status' => $status,
            'resume_id' => $this->user->resumes()->value('id'), 'subject' => "{$position} - Application",
        ]);
        $app->strategies()->create(['match' => [], 'resume_selection' => [], 'skill_strategy' => [], 'meta' => [],
            'application_strategy' => []]);

        return $app;
    }

    public function test_index_filters_by_status_country_kind_and_search(): void
    {
        $this->application('Backend Developer');
        $this->application('Frontend Developer', ApplicationStatus::Generated);
        $this->application('Cloud Engineer', job: ['country_id' => Country::where('code', 'TH')->value('id')]);
        $this->application('Open application', job: ['kind' => JobPosting::KIND_SPECULATIVE]);
        $sg = Country::where('code', 'SG')->value('id');

        $index = fn (array $query) => $this->actingAs($this->user)->get(route('applications.index', $query));

        $index([])->assertInertia(fn ($p) => $p->has('applications.data', 4)->has('countries'));
        $index(['status' => 'approved', 'country' => $sg])->assertInertia(fn ($p) => $p->has('applications.data', 2));
        $index(['kind' => 'speculative'])->assertInertia(fn ($p) => $p->has('applications.data', 1)
            ->where('applications.data.0.kind', 'speculative'));
        $index(['q' => 'developer'])->assertInertia(fn ($p) => $p->has('applications.data', 2));
        $index(['q' => 'Cloud Engineer Co'])->assertInertia(fn ($p) => $p->has('applications.data', 1)->where('filters.q', 'Cloud Engineer Co'));
    }
}
