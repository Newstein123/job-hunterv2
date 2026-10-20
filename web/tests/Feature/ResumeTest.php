<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResumeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Resume::DISK);
    }

    public function test_upload_list_and_download(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('resumes.store'), [
            'label' => 'Backend CV',
            'file' => UploadedFile::fake()->create('backend_v3.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $resume = $user->resumes()->first();
        $this->assertSame('backend_v3.pdf', $resume->original_filename);
        Storage::disk(Resume::DISK)->assertExists($resume->path);
        $this->actingAs($user)->get(route('resumes.index'))->assertOk();
        $this->actingAs($user)->get(route('resumes.download', $resume))->assertDownload('backend_v3.pdf');
    }

    public function test_rejects_unsupported_formats(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('resumes.store'), [
            'label' => 'CV', 'file' => UploadedFile::fake()->create('cv.exe', 10),
        ])->assertSessionHasErrors('file');
    }

    public function test_other_users_resumes_are_hidden(): void
    {
        $resume = Resume::factory()->withFile()->create();

        $this->actingAs(User::factory()->create())->get(route('resumes.download', $resume))->assertNotFound();
        $this->actingAs(User::factory()->create())->delete(route('resumes.destroy', $resume))->assertNotFound();
    }

    public function test_resume_attached_to_approved_application_cannot_be_deleted(): void
    {
        $application = Application::factory()->status(ApplicationStatus::Approved)->create();
        $resume = Resume::factory()->withFile()->create(['user_id' => $application->user_id]);
        $application->update(['resume_id' => $resume->id]);

        $this->actingAs($application->user)->delete(route('resumes.destroy', $resume));
        $this->assertModelExists($resume);

        $this->actingAs($application->user)->patch(route('resumes.update', $resume), ['is_active' => false]);
        $this->assertFalse($resume->fresh()->is_active);
    }

    public function test_preview_shows_pdf_inline_and_text_formats_as_plain_text(): void
    {
        $user = User::factory()->create();
        $pdf = Resume::factory()->withFile('%PDF-1.4 test')->for($user)->create(['original_filename' => 'cv.pdf']);
        $md = Resume::factory()->withFile("# Alex\n<script>alert(1)</script>")->for($user)->create(['original_filename' => 'cv.md']);

        $this->actingAs($user)->get(route('resumes.preview', $pdf))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="cv.pdf"');

        // Served as text, never HTML, so uploaded markup can't run.
        $this->actingAs($user)->get(route('resumes.preview', $md))->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_preview_extracts_docx_text(): void
    {
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'cv').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Senior PHP Developer</w:t></w:r></w:p><w:p><w:r><w:t>Laravel &amp; MySQL</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $docx = Resume::factory()->withFile((string) file_get_contents($path))->for($user)->create(['original_filename' => 'cv.docx']);
        @unlink($path);

        $response = $this->actingAs($user)->get(route('resumes.preview', $docx))->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame("Senior PHP Developer\nLaravel & MySQL", $response->getContent());
    }

    public function test_preview_is_private(): void
    {
        $resume = Resume::factory()->withFile()->create();

        $this->actingAs(User::factory()->create())->get(route('resumes.preview', $resume))->assertNotFound();
    }

    public function test_resume_changes_recheck_open_jobs_only(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        CareerProfile::factory()->for($user)->create();
        $existing = Resume::factory()->withFile()->for($user)->create();
        $match = ['fit' => JobMatch::PARTIAL, 'required_covered' => 1, 'required_total' => 2, 'preferred_covered' => 0,
            'preferred_total' => 0, 'matching_skills' => [], 'missing_skills' => [], 'match' => []];
        $analyzed = JobPosting::factory()->for($user)->create(['analysis_state' => JobPosting::ANALYSIS_DONE]);
        $analyzed->match()->create($match);
        $drafted = JobPosting::factory()->for($user)->create(['analysis_state' => JobPosting::ANALYSIS_DONE]);
        $drafted->match()->create($match);
        Application::factory()->for($drafted, 'job')->create();
        $sent = JobPosting::factory()->for($user)->create(['analysis_state' => JobPosting::ANALYSIS_DONE]);
        $sent->match()->create($match);
        Application::factory()->for($sent, 'job')->create(['status' => ApplicationStatus::Sent]);
        JobPosting::factory()->for($user)->create(); // never analyzed: left for "Analyze all"

        $this->actingAs($user)->post(route('resumes.store'), [
            'label' => 'Singapore CV', 'file' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ])->assertInertiaFlash('toast.message', 'Resume uploaded. Checking it now… Re-checking 2 open job(s) against your resumes…');

        Queue::assertPushed(AnalyzeJobPosting::class, 2);
        Queue::assertPushed(AnalyzeJobPosting::class, fn ($job) => $job->posting->is($analyzed));
        Queue::assertNotPushed(AnalyzeJobPosting::class, fn ($job) => $job->posting->is($sent));

        // Jobs already queued aren't queued twice: they read the resumes when they run.
        $user->jobPostings()->update(['analysis_state' => JobPosting::ANALYSIS_DONE]);
        // Renaming doesn't change fit; deactivating does.
        $this->actingAs($user)->patch(route('resumes.update', $existing), ['label' => 'Old CV']);
        $this->assertSame(0, $user->jobPostings()->where('analysis_state', JobPosting::ANALYSIS_PENDING)->count());
        $this->actingAs($user)->patch(route('resumes.update', $existing), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($existing->refresh()->is_active);
        $this->assertSame([$analyzed->id, $drafted->id], $user->jobPostings()->where('analysis_state', JobPosting::ANALYSIS_PENDING)->orderBy('id')->pluck('id')->all());
    }
}
