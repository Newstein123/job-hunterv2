<?php

namespace App\Services\AiService;

use App\Models\Country;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use Carbon\CarbonInterface;

/**
 * Stores what the AI service learned about a job: the analysis (+ normalized requirements)
 * and the deterministic fit against the user's profile. Shared by the match-only analysis
 * and full application generation. Call inside a transaction.
 */
class AnalysisRecorder
{
    /**
     * @param  array<string, mixed>  $result  MatchPreview or PipelineResult JSON
     * @param  array<int, int>  $resumeIds  the user's resumes that were sent
     */
    public function record(JobPosting $job, array $result, array $resumeIds, ?CarbonInterface $profileVersion): JobAnalysis
    {
        $analysisData = $result['job_analysis'];

        $analysis = $job->analyses()->create([
            'data' => $analysisData,
            'warnings' => $result['meta']['warnings'] ?? [],
            'model' => $result['meta']['model'],
            'prompt_version' => $result['meta']['prompt_versions']['job_analysis'] ?? 'unknown',
            'description_hash' => sha1($job->description),
        ]);
        $rows = [];
        foreach (JobAnalysis::REQUIREMENT_FIELDS as $field => $type) {
            foreach ($analysisData[$field] ?? [] as $value) {
                $rows[] = ['type' => $type, 'value' => mb_substr((string) $value, 0, 500)];
            }
        }
        $analysis->requirements()->createMany($rows);

        // A pasted description may have no metadata; fill blanks from the extraction.
        $job->fill(array_filter([
            'position' => $job->position ?: ($analysisData['title'] ?? null),
            'company' => $job->company ?: ($analysisData['company'] ?? null),
            'location' => $job->location ?: ($analysisData['location'] ?? null),
            // Only a supported country; an unknown one stays empty rather than inventing a market.
            'country_id' => $job->country_id ?: Country::resolve($analysisData['country'] ?? null)?->id,
        ]));
        $job->forceFill(['analysis_state' => JobPosting::ANALYSIS_DONE, 'analysis_error' => null])->save();

        $match = $result['match'];
        $summary = $match['summary'];
        $requirements = array_column(array_filter(
            $match['requirement_checks'] ?? [],
            fn (array $c) => ($c['kind'] ?? 'requirement') === 'requirement',
        ), 'requirement');
        $recommended = (int) ($result['resume_selection']['recommended_resume_id'] ?? 0);

        $job->match()->updateOrCreate([], [
            'recommended_resume_id' => in_array($recommended, $resumeIds, true) ? $recommended : null,
            'fit' => JobMatch::fitFor((int) $summary['required_covered'], (int) $summary['required_total']),
            'required_covered' => $summary['required_covered'],
            'required_total' => $summary['required_total'],
            'preferred_covered' => $summary['preferred_covered'],
            'preferred_total' => $summary['preferred_total'],
            // Skill badges only; degree/years/duty requirements are shown from requirement_checks.
            'matching_skills' => array_column(array_filter(
                [...$match['matching_required'], ...$match['matching_preferred']],
                fn (array $m) => ($m['kind'] ?? 'skill') === 'skill',
            ), 'skill'),
            'missing_skills' => array_values(array_diff([...$match['missing_required'], ...$match['missing_preferred']], $requirements)),
            'reason' => $result['resume_selection']['reason'] ?? null,
            'match' => $match,
            'resume_scores' => $result['resume_selection']['scores'] ?? null,
            'profile_snapshot_at' => $profileVersion,
        ]);

        return $analysis;
    }
}
