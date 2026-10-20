<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User rule overrides. Anything left blank falls back to the AI service's default/country profile.
 */
class ApplicationRuleController extends Controller
{
    /** Placeholders only; the source of truth is ai-service/app/rules/profiles/default.json. */
    private const DEFAULTS = [
        'email_rules' => [
            'subject_format' => 'rotates between the course subject lines', 'letter_style' => 'modern', 'language' => 'English',
            'max_words' => 200, 'paragraph_count' => 3, 'tone' => 'professional',
            'include_linkedin' => true, 'include_phone' => true, 'include_salary' => false,
        ],
        'application_rules' => [
            'cover_letter' => 'when_requested', 'mention_projects' => true, 'max_emphasized_skills' => 5,
            'cta' => 'I would welcome the opportunity to discuss how I can contribute to the team.',
        ],
        'sending_rules' => [
            'timezone' => 'UTC (or the job country\'s timezone)', 'start_time' => '09:00', 'end_time' => '17:00',
            'allowed_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        ],
        'cv_rules' => ['preferred_formats' => ['pdf'], 'max_pages' => null],
    ];

    private const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function edit(Request $request): Response
    {
        return Inertia::render('rules/edit', [
            'rules' => $request->user()->applicationRule->rules ?? (object) [],
            'defaults' => self::DEFAULTS,
            'weekdays' => self::WEEKDAYS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $bool = ['nullable', Rule::in(['', '0', '1'])];
        $v = $request->validate([
            'email_rules.subject_format' => ['nullable', 'string', 'max:200'],
            'email_rules.letter_style' => ['nullable', Rule::in(['modern', 'traditional'])],
            'email_rules.language' => ['nullable', 'string', 'max:50'],
            'email_rules.max_words' => ['nullable', 'integer', 'min:50', 'max:600'],
            'email_rules.paragraph_count' => ['nullable', 'integer', 'min:1', 'max:6'],
            'email_rules.tone' => ['nullable', 'string', 'max:50'],
            'email_rules.include_linkedin' => $bool,
            'email_rules.include_phone' => $bool,
            'email_rules.include_salary' => $bool,
            'application_rules.cover_letter' => ['nullable', Rule::in(['never', 'when_requested', 'always'])],
            'application_rules.mention_projects' => $bool,
            'application_rules.cta' => ['nullable', 'string', 'max:300'],
            'application_rules.skills_to_emphasize' => ['nullable', 'string', 'max:500'],
            'application_rules.claims_to_avoid' => ['nullable', 'string', 'max:2000'],
            'application_rules.max_emphasized_skills' => ['nullable', 'integer', 'min:1', 'max:10'],
            'sending_rules.timezone' => ['nullable', 'timezone:all'],
            'sending_rules.allowed_days' => ['nullable', 'array'],
            'sending_rules.allowed_days.*' => [Rule::in(self::WEEKDAYS)],
            'sending_rules.start_time' => ['nullable', 'date_format:H:i', 'required_with:sending_rules.end_time'],
            'sending_rules.end_time' => ['nullable', 'date_format:H:i', 'after:sending_rules.start_time'],
            'cv_rules.preferred_formats' => ['nullable', 'array'],
            'cv_rules.preferred_formats.*' => [Rule::in(['pdf', 'docx'])],
            'cv_rules.max_pages' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $rules = [];
        foreach ($v as $section => $fields) {
            foreach ($fields ?? [] as $key => $value) {
                $value = match ($key) {
                    'include_linkedin', 'include_phone', 'include_salary', 'mention_projects' => $value === null || $value === '' ? null : $value === '1',
                    'max_words', 'paragraph_count', 'max_emphasized_skills', 'max_pages' => $value === null ? null : (int) $value,
                    'skills_to_emphasize' => $this->csv($value) ?: null,
                    'claims_to_avoid' => $this->lines($value) ?: null,
                    'allowed_days', 'preferred_formats' => $value ?: null,
                    default => $value,
                };
                if ($value !== null && $value !== '') {
                    $rules[$section][$key] = $value;
                }
            }
        }

        $request->user()->applicationRule()->updateOrCreate([], ['rules' => $rules]);
        $this->toast('Rules saved.');

        return back();
    }
}
