export type ApplicationStatus =
    | 'draft'
    | 'generated'
    | 'approved'
    | 'queued'
    | 'sent'
    | 'replied'
    | 'interview'
    | 'rejected'
    | 'offer'
    | 'withdrawn';

export type GenerationState = 'pending' | 'running' | 'failed' | 'done';

export type Option = { value: string; label: string };

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

export type CareerExperience = {
    id: number;
    title: string;
    company: string;
    location: string | null;
    start_date: string;
    end_date: string | null;
    summary: string | null;
    achievements: string[] | null;
    skills: string[] | null;
};

export type CareerSkill = {
    id: number;
    name: string;
    level: string | null;
    years: number | null;
};

export type CareerProject = {
    id: number;
    name: string;
    description: string | null;
    skills: string[] | null;
    url: string | null;
};

export type CareerEducation = {
    id: number;
    institution: string;
    degree: string;
    field: string | null;
    start_date: string | null;
    end_date: string | null;
};

export type CareerProfile = {
    id: number;
    full_name: string;
    email: string;
    phone: string | null;
    whatsapp: string | null;
    linkedin: string | null;
    location: string | null;
    availability: string | null;
    headline: string | null;
    summary: string | null;
    languages: string[] | null;
    experiences: CareerExperience[];
    skills: CareerSkill[];
    projects: CareerProject[];
    education: CareerEducation[];
};

export type Resume = {
    id: number;
    label: string;
    original_filename: string;
    mime_type: string;
    size: number;
    is_active: boolean;
    review_state: 'pending' | 'running' | 'done' | 'failed' | null;
    review: ResumeReview | null;
    review_error: string | null;
    reviewed_at: string | null;
    created_at: string;
};

export type ResumeCheck = {
    id: string;
    label: string;
    status: 'pass' | 'missing' | 'check';
    detail: string;
    evidence: string | null;
    group?: 'course' | 'ats';
};

export type ResumeSuggestion = {
    title: string;
    detail: string;
    quote: string | null;
    example: string | null;
};

export type ResumeReview = {
    checks: ResumeCheck[];
    suggestions: ResumeSuggestion[];
    word_count: number;
    page_count: number | null;
};

export type JobPosting = {
    id: number;
    position: string | null;
    company: string | null;
    description: string;
    url: string | null;
    location: string | null;
    country_id: number | null;
    country?: { id: number; name: string } | null;
    contact_email: string | null;
    cc_emails?: string[] | null;
    source: string | null;
    created_at: string;
};

export type CountryOption = { id: number; name: string; code: string };

export type JobAnalysis = {
    title: string;
    company: string;
    seniority: string;
    location: string;
    country: string;
    required_skills: string[];
    preferred_skills: string[];
    responsibilities: string[];
    keywords: string[];
    requirements: string[];
};

export type ImportResult = {
    imported: number;
    duplicates: number[];
    errors: { row: number; messages: string[] }[];
    unsupported_countries: Record<string, number[]>;
    supported: string[];
    analysis: 'queued' | 'needs_profile' | 'none' | 'drafting';
};

export type ContactFlag = 'hiring' | 'general' | 'other_team' | 'person';

export type ReviewDraft = {
    id: number;
    job_id: number;
    company: string | null;
    position: string | null;
    country: string | null;
    outside_targets: boolean;
    to: string | null;
    contact_flag: ContactFlag | null;
    website: string | null;
    resume: string | null;
    subject: string | null;
    body: string | null;
    errors: string[];
    warnings: string[];
};

export type Fit = 'strong' | 'partial' | 'weak' | 'unknown';

export type AnalysisState = 'pending' | 'running' | 'failed' | 'done' | null;

export type JobMatchSummary = {
    fit: Fit;
    required_covered: number;
    required_total: number;
    preferred_covered: number;
    preferred_total: number;
    matching_skills: string[];
    missing_skills: string[];
    reason: string | null;
    recommended_resume: string | null;
    requirement_checks?: RequirementCheck[];
    stale: boolean;
};

export type RequirementCheck = {
    requirement: string;
    required: boolean;
    status: 'met' | 'partial' | 'not_met' | 'not_applicable';
    note: string | null;
    evidence: { source: string; quote: string }[];
};

export type SkillMatch = { skill: string; evidence: string[] };
export type RelevantItem = {
    id: string;
    label: string;
    matched_skills: string[];
    evidence: string[];
};

export type Strategy = {
    match: {
        matching_required: SkillMatch[];
        matching_preferred: SkillMatch[];
        missing_required: string[];
        missing_preferred: string[];
        relevant_experience: RelevantItem[];
        relevant_projects: RelevantItem[];
        relevant_education: RelevantItem[];
        summary: {
            required_covered: number;
            required_total: number;
            preferred_covered: number;
            preferred_total: number;
        };
    };
    resume_selection: {
        recommended_resume_id: string;
        recommended_filename: string;
        alternative_resume_ids: string[];
        reason: string;
        emphasize: string[];
        relevant_experience: string[];
        relevant_projects: string[];
    };
    skill_strategy: {
        emphasize: string[];
        supporting_skills: string[];
        avoid_overemphasizing: string[];
        do_not_claim: string[];
    };
    application_strategy: {
        tone: string;
        language: string;
        include_cover_letter: boolean;
        cover_letter_reason: string;
    };
    meta: {
        model: string;
        prompt_versions: Record<string, string>;
        warnings: string[];
        generated_at: string;
    };
};

export type ApplicationDetail = {
    id: number;
    status: ApplicationStatus;
    generation_state: GenerationState;
    generation_error: string | null;
    subject: string | null;
    body: string | null;
    cover_letter: string | null;
    cc: string[] | null;
    bcc: string[] | null;
    resume_id: number | null;
    validation: { errors: string[]; warnings: string[]; attempts: number };
    edited_at: string | null;
    approved_at: string | null;
    resume: { id: number; label: string; original_filename: string } | null;
    job: Pick<
        JobPosting,
        'id' | 'position' | 'company' | 'location' | 'contact_email' | 'url'
    > & { country: string | null };
    strategy: Strategy | null;
    email_account_id: number | null;
    outbound: OutboundMessage | null;
    conversation: ConversationItem[];
    reply_state: ReplyState | null;
    replies: {
        id: number;
        from_email: string | null;
        received_at: string | null;
    }[];
    history: {
        id: number;
        from_status: string | null;
        to_status: string;
        note: string | null;
        created_at: string;
    }[];
};

export type ApplicationRow = {
    id: number;
    status: ApplicationStatus;
    generation_state: GenerationState;
    position: string;
    company: string | null;
    country?: string | null;
    kind?: 'posting' | 'speculative';
    resume?: string | null;
    has_errors?: boolean;
    reply_state?: ReplyState | null;
    scheduled_at?: string | null;
    updated_at: string;
};

export type EmailAccountStatus =
    | 'active'
    | 'needs_reauth'
    | 'restricted'
    | 'disabled';

export type EmailAccount = {
    id: number;
    provider: string;
    email: string;
    status: EmailAccountStatus;
    status_reason: string | null;
    daily_limit: number;
    is_default: boolean;
    sent_today?: number;
    can_read?: boolean;
    queued?: number;
    last_synced_at?: string | null;
    last_sent_at?: string | null;
};

export type OutboundMessage = {
    id: number;
    status:
        | 'queued'
        | 'scheduled'
        | 'sending'
        | 'sent'
        | 'failed'
        | 'retrying'
        | 'cancelled';
    to_email: string | null;
    cc: string[] | null;
    bcc: string[] | null;
    from_email: string | null;
    attempts: number;
    last_error: string | null;
    scheduled_at: string | null;
    sent_at: string | null;
};

export type BulkResult = {
    message: string;
    skipped: { job: string; reason: string }[];
};

export type ReplyCategory =
    | 'interview_invite'
    | 'info_request'
    | 'rejection'
    | 'offer'
    | 'acknowledgement'
    | 'auto_reply'
    | 'other';

export type ReplyAnalysis = {
    category: ReplyCategory;
    summary: string;
    requests: string[];
    questions: string[];
    dates_mentioned: string[];
    evidence_quotes: string[];
    suggested_status: string | null;
    warnings: string[];
};

export type ConversationItem = {
    id: number;
    kind: 'application' | 'reply' | 'answer';
    status: string;
    from: string | null;
    to: string | null;
    cc: string[] | null;
    subject: string | null;
    body: string | null;
    latest_text: string | null;
    snippet: string | null;
    is_auto_reply: boolean;
    is_bounce: boolean;
    analysis: ReplyAnalysis | null;
    analysis_state: 'pending' | 'running' | 'failed' | 'done' | null;
    analysis_error: string | null;
    in_reply_to_id: number | null;
    validation: {
        errors?: string[];
        warnings?: string[];
        needs_input?: string[];
    } | null;
    attach_resume: boolean;
    last_error: string | null;
    scheduled_at: string | null;
    at: string;
    can_read: boolean;
    gmail_url: string | null;
};

export type AddableSkill = {
    skill: string;
    required: boolean;
    evidence: string[];
};

export type ResumeComparisonRow = {
    resume_id: number;
    label: string;
    filename: string;
    is_active: boolean;
    recommended: boolean;
    required_covered: string[];
    required_total: number;
    preferred_covered: string[];
    preferred_total: number;
    keyword_hits: string[];
    cv_rule_issues: string[];
    could_add: AddableSkill[];
    gaps: string[];
    page_count: number | null;
    word_count: number | null;
    ats_match: number | null;
    ats_found: string[];
    ats_missing: string[];
};

export type ResumeInsight = {
    resume_id: number;
    jobs: number;
    best_for: number;
    avg_required_coverage: number;
    could_add: { skill: string; jobs: number }[];
    gaps: { skill: string; jobs: number }[];
};

export type ReplyState = {
    state: 'awaiting_you' | 'reply_draft' | 'reply_scheduled' | 'you_replied';
    at: string | null;
};
