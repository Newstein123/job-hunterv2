<?php

namespace App\Http\Controllers;

use App\Models\CareerEducation;
use App\Models\CareerExperience;
use App\Models\CareerProfile;
use App\Models\CareerProject;
use App\Models\CareerSkill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Create/update/delete entries in the career profile sections:
 * experiences, skills, projects, education.
 */
class CareerItemController extends Controller
{
    public const SECTIONS = ['experiences', 'skills', 'projects', 'education'];

    public function store(Request $request, string $section): RedirectResponse
    {
        $profile = $this->profile($request);
        $this->relation($profile, $section)->create($this->validated($request, $section, $profile));
        $this->toast('Saved.');

        return back();
    }

    public function update(Request $request, string $section, int $item): RedirectResponse
    {
        $profile = $this->profile($request);
        $model = $this->find($profile, $section, $item);
        $model->update($this->validated($request, $section, $profile, $model));
        $this->toast('Saved.');

        return back();
    }

    public function destroy(Request $request, string $section, int $item): RedirectResponse
    {
        $this->find($this->profile($request), $section, $item)->delete();
        $this->toast('Removed.');

        return back();
    }

    private function profile(Request $request): CareerProfile
    {
        $profile = $request->user()->careerProfile;
        if ($profile === null) {
            abort(409, 'Create your career profile first.');
        }

        return $profile;
    }

    /** @return HasMany<CareerExperience, CareerProfile>|HasMany<CareerSkill, CareerProfile>|HasMany<CareerProject, CareerProfile>|HasMany<CareerEducation, CareerProfile> */
    private function relation(CareerProfile $profile, string $section): HasMany
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        return $profile->{$section}();
    }

    private function find(CareerProfile $profile, string $section, int $id): Model
    {
        return $this->relation($profile, $section)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $section, CareerProfile $profile, ?Model $existing = null): array
    {
        return match ($section) {
            'experiences' => $this->experience($request),
            'skills' => $request->validate([
                'name' => ['required', 'string', 'max:100', Rule::unique('career_skills')
                    ->where('career_profile_id', $profile->id)->ignore($existing?->getKey())],
                'level' => ['nullable', Rule::in(['beginner', 'intermediate', 'advanced', 'expert'])],
                'years' => ['nullable', 'numeric', 'min:0', 'max:60'],
            ]),
            'projects' => $this->project($request),
            'education' => $request->validate([
                'institution' => ['required', 'string', 'max:255'],
                'degree' => ['required', 'string', 'max:255'],
                'field' => ['nullable', 'string', 'max:255'],
                'start_date' => ['nullable', 'date'],
                'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            ]),
            default => abort(404),
        };
    }

    /** @return array<string, mixed> */
    private function experience(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'achievements' => ['nullable', 'string', 'max:5000'],
            'skills' => ['nullable', 'string', 'max:1000'],
        ]);

        return [...$data, 'achievements' => $this->lines($data['achievements'] ?? null),
            'skills' => $this->csv($data['skills'] ?? null)];
    }

    /** @return array<string, mixed> */
    private function project(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'skills' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'url', 'max:255'],
        ]);

        return [...$data, 'skills' => $this->csv($data['skills'] ?? null)];
    }
}
