<?php

namespace App\Services\AiService;

use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Maps Laravel models to the AI service's request schema (ai-service/app/api/schemas.py).
 */
class PipelinePayload
{
    /**
     * @param  Collection<int, Resume>  $resumes
     * @return array<string, mixed>
     */
    public static function forApplication(User $user, JobPosting $job, Collection $resumes): array
    {
        return [
            'job' => self::job($job),
            'profile' => self::profile($user->careerProfile),
            'resumes' => $resumes->map(fn (Resume $r) => [
                'id' => (string) $r->id,
                'filename' => $r->original_filename,
                'content_base64' => base64_encode(Storage::disk(Resume::disk())->get($r->path)),
            ])->values()->all(),
            'user_rules' => $user->applicationRule?->rules ?: null,
            // Reuse the analysis made at import if the description hasn't changed since (saves one AI call).
            'job_analysis' => self::reusableAnalysis($job),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function reusableAnalysis(JobPosting $job): ?array
    {
        $analysis = $job->latestAnalysis()->first();

        return $analysis && $analysis->description_hash === sha1($job->description) ? $analysis->data : null;
    }

    /** @return array<string, mixed> */
    public static function job(JobPosting $job): array
    {
        $job->loadMissing('country');

        return array_filter([
            'description' => $job->description,
            'position' => $job->position,
            'company' => $job->company,
            'url' => $job->url,
            'location' => $job->location,
            // The name selects the AI service's country rules profile.
            'country' => $job->country?->name,
            'contact_email' => $job->contact_email,
            'source' => $job->source,
            'kind' => $job->kind,
            'industry' => $job->industry,
            'specialties' => $job->specialties,
            'website' => $job->website,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string, mixed> */
    public static function profile(CareerProfile $profile): array
    {
        $profile->loadMissing(['experiences', 'skills', 'projects', 'education']);

        return [
            'name' => $profile->full_name,
            'email' => $profile->email,
            'phone' => $profile->phone,
            'whatsapp' => $profile->whatsapp,
            'linkedin' => $profile->linkedin,
            'location' => $profile->location,
            'availability' => $profile->availability,
            'headline' => $profile->headline,
            'summary' => $profile->summary,
            'languages' => $profile->languages ?? [],
            'skills' => $profile->skills->map(fn ($s) => [
                'name' => $s->name,
                'level' => $s->level,
                'years' => $s->years,
            ])->all(),
            'experiences' => $profile->experiences->map(fn ($e) => [
                'id' => "exp_{$e->id}",
                'title' => $e->title,
                'company' => $e->company,
                'start' => $e->start_date->toDateString(),
                'end' => $e->end_date?->toDateString(),
                'location' => $e->location,
                'summary' => $e->summary ?? '',
                'achievements' => $e->achievements ?? [],
                'skills' => $e->skills ?? [],
            ])->all(),
            'projects' => $profile->projects->map(fn ($p) => [
                'id' => "proj_{$p->id}",
                'name' => $p->name,
                'description' => $p->description ?? '',
                'skills' => $p->skills ?? [],
                'url' => $p->url,
            ])->all(),
            'education' => $profile->education->map(fn ($e) => [
                'id' => "edu_{$e->id}",
                'institution' => $e->institution,
                'degree' => $e->degree,
                'field' => $e->field,
                'start' => $e->start_date?->toDateString(),
                'end' => $e->end_date?->toDateString(),
            ])->all(),
        ];
    }
}
