<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Jobs\ReviewResume;
use App\Models\Application;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Services\DocxText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResumeController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('resumes/index', [
            'resumes' => $request->user()->resumes()->latest()->get(),
            'insights' => $this->insights($request),
            'reviewing' => $request->user()->resumes()
                ->whereIn('review_state', [Resume::REVIEW_PENDING, Resume::REVIEW_RUNNING])->count(),
            'analyzing' => $request->user()->jobPostings()
                ->whereIn('analysis_state', [JobPosting::ANALYSIS_PENDING, JobPosting::ANALYSIS_RUNNING])->count(),
            'extensions' => Resume::EXTENSIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'max:5120', 'extensions:'.implode(',', Resume::EXTENSIONS)],
        ]);
        $file = $request->file('file');
        $user = $request->user();

        $resume = $user->resumes()->create([
            'label' => $data['label'],
            'original_filename' => $file->getClientOriginalName(),
            'path' => $file->store("resumes/{$user->id}", Resume::DISK),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
        ]);
        ReviewResume::start($resume); // CV check: checklist + AI suggestions, shown on this page
        $this->toastWithRecheck('Resume uploaded. Checking it now…', $user->reanalyzeOpenJobs());

        return back();
    }

    public function update(Request $request, Resume $resume): RedirectResponse
    {
        $this->ensureOwns($request, $resume);
        $resume->update($request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]));
        // Turning a resume on or off changes which one fits each job best.
        $rechecked = $resume->wasChanged('is_active') ? $request->user()->reanalyzeOpenJobs() : 0;
        $this->toastWithRecheck('Resume updated.', $rechecked);

        return back();
    }

    public function destroy(Request $request, Resume $resume): RedirectResponse
    {
        $this->ensureOwns($request, $resume);
        $inUse = Application::where('resume_id', $resume->id)
            ->whereIn('status', [ApplicationStatus::Approved, ApplicationStatus::Queued])->exists();
        if ($inUse) {
            $this->toast('This resume is attached to an approved application. Deactivate it instead.', 'error');

            return back();
        }
        Storage::disk(Resume::DISK)->delete($resume->path);
        $resume->delete();
        $this->toastWithRecheck('Resume deleted.', $request->user()->reanalyzeOpenJobs());

        return back();
    }

    /** Run the CV check again (e.g. after uploading a new version or changing CV rules). */
    public function review(Request $request, Resume $resume): RedirectResponse
    {
        $this->ensureOwns($request, $resume);
        ReviewResume::start($resume);
        $this->toast('Checking your CV…', 'info');

        return back();
    }

    private function toastWithRecheck(string $message, int $jobs): void
    {
        $this->toast($jobs > 0 ? "{$message} Re-checking {$jobs} open job(s) against your resumes…" : $message);
    }

    /**
     * Inline preview for the "View" dialog: PDFs as-is, text files and DOCX as plain text.
     * Text is never served as HTML, so an uploaded file can't run script in the app.
     */
    public function preview(Request $request, Resume $resume): HttpResponse
    {
        $this->ensureOwns($request, $resume);
        $disk = Storage::disk(Resume::DISK);
        abort_unless($disk->exists($resume->path), 404);
        $bytes = (string) $disk->get($resume->path);
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.addcslashes($resume->original_filename, '"\\').'"',
            'Cache-Control' => 'private, no-store',
        ];

        return match ($resume->extension()) {
            'pdf' => response($bytes, 200, [...$headers, 'Content-Type' => 'application/pdf']),
            'docx' => response(DocxText::fromBytes($bytes) ?? 'This document could not be read.', 200,
                [...$headers, 'Content-Type' => 'text/plain; charset=UTF-8']),
            default => response($bytes, 200, [...$headers, 'Content-Type' => 'text/plain; charset=UTF-8']),
        };
    }

    public function download(Request $request, Resume $resume): StreamedResponse
    {
        $this->ensureOwns($request, $resume);

        return Storage::disk(Resume::DISK)->download($resume->path, $resume->original_filename);
    }

    /**
     * CV analysis across every analyzed job: how often each resume is the best fit, its average
     * required-skill coverage, and the skills most often worth adding (the profile backs them up).
     *
     * @return array<int, array<string, mixed>>
     */
    private function insights(Request $request): array
    {
        $matches = JobMatch::query()->whereIn('job_id', $request->user()->jobPostings()->select('id'))
            ->whereNotNull('resume_scores')->get(['recommended_resume_id', 'required_total', 'resume_scores']);
        if ($matches->isEmpty()) {
            return [];
        }

        $stats = [];
        foreach ($matches as $match) {
            foreach ($match->resume_scores ?? [] as $score) {
                $id = (int) $score['resume_id'];
                $stats[$id] ??= ['jobs' => 0, 'best_for' => 0, 'coverage' => 0.0, 'add' => [], 'gaps' => []];
                $stats[$id]['jobs']++;
                $stats[$id]['best_for'] += $match->recommended_resume_id === $id ? 1 : 0;
                $stats[$id]['coverage'] += $match->required_total > 0 ? count($score['required_covered']) / $match->required_total : 0;
                foreach ($score['could_add'] ?? [] as $add) {
                    $stats[$id]['add'][$add['skill']] = ($stats[$id]['add'][$add['skill']] ?? 0) + 1;
                }
                foreach ($score['gaps'] ?? [] as $gap) {
                    $stats[$id]['gaps'][$gap] = ($stats[$id]['gaps'][$gap] ?? 0) + 1;
                }
            }
        }

        $top = function (array $counts): array {
            arsort($counts);

            return collect($counts)->take(6)->map(fn ($n, $skill) => ['skill' => $skill, 'jobs' => $n])->values()->all();
        };

        return collect($stats)->map(fn ($s, $id) => [
            'resume_id' => $id,
            'jobs' => $s['jobs'],
            'best_for' => $s['best_for'],
            'avg_required_coverage' => (int) round(100 * $s['coverage'] / max(1, $s['jobs'])),
            'could_add' => $top($s['add']),
            'gaps' => $top($s['gaps']),
        ])->values()->all();
    }
}
