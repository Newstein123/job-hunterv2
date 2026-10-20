<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeJobPosting;
use App\Models\Country;
use App\Models\JobPosting;
use App\Services\ApplicationStarter;
use App\Services\JobImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\Exception\ReaderException;

class JobImportController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('jobs/import', [
            'maxRows' => JobImporter::MAX_ROWS,
            'countries' => Country::options(),
            'resumes' => $request->user()->resumes()->where('is_active', true)->orderBy('label')->get(['id', 'label']),
        ]);
    }

    public function store(Request $request, JobImporter $importer, ApplicationStarter $starter): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx'],
            'kind' => ['nullable', Rule::in([JobPosting::KIND_POSTING, JobPosting::KIND_SPECULATIVE])],
            'position' => ['required_if:kind,speculative', 'nullable', 'string', 'max:255'],
            'resume_id' => ['required_if:kind,speculative', 'nullable', 'integer',
                Rule::exists('resumes', 'id')->where('user_id', $user->id)->where('is_active', true)],
        ]);
        $file = $request->file('file');
        $speculative = ($data['kind'] ?? null) === JobPosting::KIND_SPECULATIVE;
        if ($speculative && ! $user->careerProfile) {
            throw ValidationException::withMessages(['file' => 'Add your career profile first: the emails are written from it.']);
        }

        try {
            $result = $speculative
                ? $importer->importCompanies($user, $file->getRealPath(), $file->getClientOriginalExtension(), $data['position'], (int) $data['resume_id'])
                : $importer->import($user, $file->getRealPath(), $file->getClientOriginalExtension());
        } catch (InvalidArgumentException|IOException|ReaderException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        if ($speculative) {
            // No posting to analyze: draft each email straight away, for batch review.
            foreach (JobPosting::whereKey($result['job_ids'])->get() as $job) {
                $starter->start($user, $job);
            }
            Inertia::flash('importResult', [...collect($result)->except('job_ids')->all(), 'analysis' => 'drafting', 'supported' => Country::active()->pluck('name')->all()]);
            $this->toast("Imported {$result['imported']} compan(ies). Drafting emails for your review…", $result['errors'] || $result['unsupported_countries'] ? 'warning' : 'success');

            return back();
        }

        // Analyze each new job's fit in the background (one AI call per job, nothing drafted).
        $canAnalyze = $request->user()->canAnalyzeJobs();
        if ($canAnalyze && $result['job_ids'] !== []) {
            AnalyzeJobPosting::start(...JobPosting::whereKey($result['job_ids'])->get()->all());
        }

        Inertia::flash('importResult', [
            ...collect($result)->except('job_ids')->all(),
            'analysis' => $result['job_ids'] === [] ? 'none' : ($canAnalyze ? 'queued' : 'needs_profile'),
            'supported' => Country::active()->pluck('name')->all(),
        ]);
        $this->toast("Imported {$result['imported']} job(s).", $result['errors'] || $result['unsupported_countries'] ? 'warning' : 'success');

        return back();
    }
}
