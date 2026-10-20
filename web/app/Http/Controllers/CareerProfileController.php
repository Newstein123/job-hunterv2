<?php

namespace App\Http\Controllers;

use App\Models\CareerProfile;
use App\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;

class CareerProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $profile = $request->user()->careerProfile?->load(['experiences', 'skills', 'projects', 'education', 'targetCountries:id']);

        return Inertia::render('career-profile/edit', [
            'profile' => $profile,
            'countries' => Country::options(),
            'targetCountryIds' => $profile?->targetCountries->modelKeys() ?? [],
            'defaults' => ['full_name' => $request->user()->name, 'email' => $request->user()->email],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'linkedin' => ['nullable', 'url', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'availability' => ['nullable', 'string', 'max:100'],
            'headline' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'languages' => ['nullable', 'string', 'max:500'],
            'target_country_ids' => ['nullable', 'array'],
            'target_country_ids.*' => ['integer', Rule::exists('countries', 'id')->where('is_active', true)],
        ]);
        $data['languages'] = $this->csv($data['languages'] ?? null);
        $targets = array_map('intval', $data['target_country_ids'] ?? []);
        unset($data['target_country_ids']);

        /** @var CareerProfile $profile */
        $profile = $request->user()->careerProfile()->updateOrCreate([], $data);
        $profile->targetCountries()->sync($targets);
        $this->toast('Career profile saved.');

        return back();
    }

    /**
     * Import a profile in the AI service's candidate_profile.json format, replacing current entries.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:1024', 'mimetypes:application/json,text/plain']]);

        try {
            $json = json_decode((string) $request->file('file')?->get(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['file' => 'The file is not valid JSON.']);
        }

        $data = Validator::make(is_array($json) ? $json : [], self::IMPORT_RULES)->validate();

        DB::transaction(function () use ($request, $data) {
            /** @var CareerProfile $profile */
            $profile = $request->user()->careerProfile()->updateOrCreate([], [
                'full_name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'whatsapp' => $data['whatsapp'] ?? null,
                'linkedin' => $data['linkedin'] ?? null,
                'location' => $data['location'] ?? null,
                'availability' => $data['availability'] ?? null,
                'headline' => $data['headline'] ?? null,
                'summary' => $data['summary'] ?? null,
                'languages' => $data['languages'] ?? [],
            ]);
            $profile->experiences()->delete();
            $profile->skills()->delete();
            $profile->projects()->delete();
            $profile->education()->delete();

            $skills = [];
            foreach ($data['skills'] ?? [] as $s) {
                $skills[mb_strtolower($s['name'])] = [
                    'name' => $s['name'], 'level' => $s['level'] ?? null, 'years' => $s['years'] ?? null,
                ];
            }
            $profile->skills()->createMany(array_values($skills));
            $profile->experiences()->createMany(array_map(fn (array $e) => [
                'title' => $e['title'], 'company' => $e['company'], 'location' => $e['location'] ?? null,
                'start_date' => $e['start'], 'end_date' => $e['end'] ?? null, 'summary' => $e['summary'] ?? null,
                'achievements' => $e['achievements'] ?? [], 'skills' => $e['skills'] ?? [],
            ], $data['experiences'] ?? []));
            $profile->projects()->createMany(array_map(fn (array $p) => [
                'name' => $p['name'], 'description' => $p['description'] ?? null,
                'skills' => $p['skills'] ?? [], 'url' => $p['url'] ?? null,
            ], $data['projects'] ?? []));
            $profile->education()->createMany(array_map(fn (array $e) => [
                'institution' => $e['institution'], 'degree' => $e['degree'], 'field' => $e['field'] ?? null,
                'start_date' => $e['start'] ?? null, 'end_date' => $e['end'] ?? null,
            ], $data['education'] ?? []));
        });

        $this->toast('Profile imported.');

        return back();
    }

    /** Mirrors CandidateProfile in ai-service/app/models/candidate.py. Only validated keys are imported. */
    private const IMPORT_RULES = [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255'],
        'phone' => ['nullable', 'string', 'max:50'],
        'whatsapp' => ['nullable', 'string', 'max:50'],
        'linkedin' => ['nullable', 'string', 'max:255'],
        'location' => ['nullable', 'string', 'max:255'],
        'availability' => ['nullable', 'string', 'max:100'],
        'headline' => ['nullable', 'string', 'max:255'],
        'summary' => ['nullable', 'string', 'max:5000'],
        'languages' => ['array'],
        'languages.*' => ['string', 'max:50'],
        'skills' => ['array'],
        'skills.*.name' => ['required', 'string', 'max:100'],
        'skills.*.level' => ['nullable', 'string', 'max:50'],
        'skills.*.years' => ['nullable', 'numeric', 'min:0', 'max:60'],
        'experiences' => ['array'],
        'experiences.*.title' => ['required', 'string', 'max:255'],
        'experiences.*.company' => ['required', 'string', 'max:255'],
        'experiences.*.location' => ['nullable', 'string', 'max:255'],
        'experiences.*.start' => ['required', 'date'],
        'experiences.*.end' => ['nullable', 'date'],
        'experiences.*.summary' => ['nullable', 'string', 'max:2000'],
        'experiences.*.achievements' => ['array'],
        'experiences.*.achievements.*' => ['string', 'max:1000'],
        'experiences.*.skills' => ['array'],
        'experiences.*.skills.*' => ['string', 'max:100'],
        'projects' => ['array'],
        'projects.*.name' => ['required', 'string', 'max:255'],
        'projects.*.description' => ['nullable', 'string', 'max:2000'],
        'projects.*.skills' => ['array'],
        'projects.*.skills.*' => ['string', 'max:100'],
        'projects.*.url' => ['nullable', 'string', 'max:255'],
        'education' => ['array'],
        'education.*.institution' => ['required', 'string', 'max:255'],
        'education.*.degree' => ['required', 'string', 'max:255'],
        'education.*.field' => ['nullable', 'string', 'max:255'],
        'education.*.start' => ['nullable', 'date'],
        'education.*.end' => ['nullable', 'date'],
    ];
}
