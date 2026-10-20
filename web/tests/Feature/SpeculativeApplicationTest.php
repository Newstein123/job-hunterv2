<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Jobs\GenerateApplicationContent;
use App\Jobs\SendEmailMessage;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use App\Services\AiService\ApplicationGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpeculativeApplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Resume $resume;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::DISK);
        $this->user = User::factory()->create();
        CareerProfile::factory()->for($this->user)->create();
        $this->resume = Resume::factory()->withFile()->for($this->user)->create(['label' => 'Web Developer CV']);
        Resume::factory()->withFile()->for($this->user)->create(['label' => 'Other CV']);
    }

    /** Columns as exported from a company database ("The Grid"). */
    private function companyList(): UploadedFile
    {
        $rows = [
            'Company Name,Contact number,Email,Website,Headquarter,Industry,Specialties,Spending Power,Source',
            'Acme Web,+61 400,101info@acmeweb.example,https://acmeweb.example,Australia,"ICT,IT Consulting","web design,seo",,The Grid',
            'Acme Web AU,+61 400,sales@acmeweb.example,https://acmeweb.example,Australia,ICT,,,The Grid', // same domain
            'Beta Tech,+65 1,jane.doe@betatech.example,https://betatech.example,Singapore,ICT,payments,,The Grid',
            'Gamma,+1 1,info@gamma.example,https://gamma.example,United States of America,ICT,,,The Grid', // unsupported
            'Delta,+65 2,,https://delta.example,Singapore,ICT,,,The Grid', // no email
            'Known Co,+65 3,careers@known.example,,Singapore,ICT,,,The Grid', // already in JobHunter
        ];

        return UploadedFile::fake()->createWithContent('companies.csv', implode("\n", $rows));
    }

    public function test_contact_flags(): void
    {
        $this->assertSame(JobPosting::CONTACT_HIRING, JobPosting::contactFlagFor('careers@x.com'));
        $this->assertSame(JobPosting::CONTACT_GENERAL, JobPosting::contactFlagFor('101info@x.com'));
        $this->assertSame(JobPosting::CONTACT_GENERAL, JobPosting::contactFlagFor('123@x.com'));
        $this->assertSame(JobPosting::CONTACT_OTHER_TEAM, JobPosting::contactFlagFor('10500sales@x.com'));
        $this->assertSame(JobPosting::CONTACT_OTHER_TEAM, JobPosting::contactFlagFor('accounts@x.com'));
        $this->assertSame(JobPosting::CONTACT_PERSON, JobPosting::contactFlagFor('jane.doe@x.com'));
    }

    public function test_import_creates_one_speculative_job_per_domain_and_starts_drafts(): void
    {
        Queue::fake();
        JobPosting::factory()->for($this->user)->create(['contact_email' => 'hr@known.example']);

        $this->actingAs($this->user)->post(route('jobs.import.store'), [
            'file' => $this->companyList(), 'kind' => 'speculative',
            'position' => 'Senior Web Developer', 'resume_id' => $this->resume->id,
        ])->assertSessionHasNoErrors()
            ->assertInertiaFlash('importResult.imported', 2)
            ->assertInertiaFlash('importResult.duplicates', [3, 7])
            ->assertInertiaFlash('importResult.analysis', 'drafting');

        $jobs = $this->user->jobPostings()->where('kind', JobPosting::KIND_SPECULATIVE)->orderBy('id')->get();
        $this->assertSame(['Acme Web', 'Beta Tech'], $jobs->pluck('company')->all());
        $this->assertSame([JobPosting::CONTACT_GENERAL, JobPosting::CONTACT_PERSON], $jobs->pluck('contact_flag')->all());
        $this->assertSame('Senior Web Developer', $jobs[0]->position);
        $this->assertSame($this->resume->id, $jobs[0]->preferred_resume_id);
        $this->assertSame('web design,seo', $jobs[0]->specialties);
        $this->assertStringContainsString('has not posted this role', $jobs[0]->description);

        Queue::assertPushed(GenerateApplicationContent::class, 2);
        Queue::assertNotPushed(AnalyzeJobPosting::class);
    }

    public function test_company_list_uploaded_as_job_posts_says_to_switch(): void
    {
        $this->actingAs($this->user)->post(route('jobs.import.store'), ['file' => $this->companyList()])
            ->assertSessionHasErrors(['file' => 'This looks like a company list (it has emails but no job descriptions). Choose "Companies (speculative)" at the top of this page, enter the position and resume, then upload it again.']);
    }

    public function test_import_needs_position_and_own_active_resume(): void
    {
        $other = Resume::factory()->withFile()->create();
        $this->actingAs($this->user)->post(route('jobs.import.store'), [
            'file' => $this->companyList(), 'kind' => 'speculative', 'resume_id' => $other->id,
        ])->assertSessionHasErrors(['position', 'resume_id']);
    }

    public function test_generation_sends_only_the_chosen_resume_and_the_company_facts(): void
    {
        $job = JobPosting::factory()->for($this->user)->create([
            'kind' => JobPosting::KIND_SPECULATIVE, 'position' => 'Senior Web Developer', 'company' => 'Acme Web',
            'industry' => 'ICT', 'specialties' => 'web design', 'preferred_resume_id' => $this->resume->id,
        ]);
        $application = Application::factory()->for($job, 'job')->create(['status' => ApplicationStatus::Draft]);
        config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret']);
        $result = json_decode(file_get_contents(base_path('tests/Fixtures/pipeline_result.json')), true);
        $result['resume_selection']['recommended_resume_id'] = (string) $this->resume->id;
        Http::fake(['ai.test/*' => Http::response($result)]);

        app(ApplicationGenerator::class)->generate($application);

        Http::assertSent(fn (HttpRequest $r) => count($r['resumes']) === 1
            && $r['resumes'][0]['id'] === (string) $this->resume->id
            && $r['job']['kind'] === 'speculative'
            && $r['job']['specialties'] === 'web design');
    }

    public function test_batch_review_approves_clean_drafts_and_skips_ones_with_errors(): void
    {
        $make = fn (array $attrs = []) => Application::factory()
            ->for(JobPosting::factory()->for($this->user)->create(['kind' => JobPosting::KIND_SPECULATIVE, 'contact_flag' => 'person']), 'job')
            ->create($attrs);
        $clean = $make();
        $broken = $make(['validation' => ['errors' => ['Says the role was advertised'], 'warnings' => []]]);
        $posting = Application::factory()->for(JobPosting::factory()->for($this->user)->create(), 'job')->create();

        $this->actingAs($this->user)->get(route('review.index'))->assertInertia(fn ($page) => $page
            ->component('review/index')
            ->has('drafts', 2)
            ->where('drafts.0.contact_flag', 'person')
            ->where('drafts.1.errors', ['Says the role was advertised']));
        $this->actingAs($this->user)->get(route('review.index', ['kind' => 'posting']))
            ->assertInertia(fn ($page) => $page->has('drafts', 1)->where('drafts.0.id', $posting->id));

        $this->actingAs($this->user)->post(route('review.approve'), ['application_ids' => [$clean->id, $broken->id]])
            ->assertInertiaFlash('toast.type', 'warning');
        $this->assertSame(ApplicationStatus::Approved, $clean->fresh()->status);
        $this->assertSame(ApplicationStatus::Generated, $broken->fresh()->status);

        $stranger = Application::factory()->create();
        $this->actingAs($this->user)->post(route('review.approve'), ['application_ids' => [$stranger->id]])
            ->assertSessionHasErrors('application_ids.0');
    }

    public function test_speculative_daily_cap_holds_first_emails_but_not_replies_or_job_posts(): void
    {
        Queue::fake([SendEmailMessage::class]);
        config(['services.mail_sending.speculative_daily_limit' => 2]);
        $account = EmailAccount::factory()->for($this->user)->create(['daily_limit' => 100]);
        $speculative = fn () => Application::factory()
            ->for(JobPosting::factory()->for($this->user)->create(['kind' => JobPosting::KIND_SPECULATIVE]), 'job')->create();
        $message = fn (Application $a, string $status, array $extra = []) => EmailMessage::forceCreate([
            'user_id' => $this->user->id, 'application_id' => $a->id, 'email_account_id' => $account->id,
            'direction' => EmailMessage::OUTBOUND, 'status' => $status, 'to_email' => 'info@x.example', ...$extra,
        ]);
        $message($speculative(), EmailMessage::SENT, ['sent_at' => now()->subHour()]);
        $message($speculative(), EmailMessage::SENT, ['sent_at' => now()->subHour()]);
        $this->assertFalse($account->hasSpeculativeCapacity());

        $waiting = $message($speculative(), EmailMessage::QUEUED, ['scheduled_at' => now()->subMinutes(10)]);
        $posting = $message(Application::factory()->for(JobPosting::factory()->for($this->user)->create(), 'job')->create(),
            EmailMessage::QUEUED, ['scheduled_at' => now()->subMinutes(5)]);

        $this->artisan('emails:dispatch-due')->assertSuccessful();
        $this->assertSame(EmailMessage::QUEUED, $waiting->fresh()->status);
        $this->assertSame(EmailMessage::SENDING, $posting->fresh()->status);
    }
}
