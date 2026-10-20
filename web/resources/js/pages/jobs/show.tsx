import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { ExternalLink, RefreshCw, Sparkles, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import JobPostingController from '@/actions/App/Http/Controllers/JobPostingController';
import FitBadge from '@/components/fit-badge';
import ResumeComparison from '@/components/jobs/resume-comparison';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import JobFormFields from '@/components/jobs/job-form-fields';
import StatusBadge from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type {
    AnalysisState,
    JobMatchSummary,
    RequirementCheck,
    ResumeComparisonRow,
    ApplicationStatus,
    GenerationState,
    JobAnalysis,
    JobPosting,
    CountryOption,
} from '@/types';

type Props = {
    job: JobPosting & {
        application: {
            id: number;
            status: ApplicationStatus;
            generation_state: GenerationState;
        } | null;
        analysis_state: AnalysisState;
        analysis_error: string | null;
    };
    analysis: JobAnalysis | null;
    match: JobMatchSummary | null;
    resumeComparison: ResumeComparisonRow[];
    sources: string[];
    countries: CountryOption[];
    outsideTargets: boolean;
    canGenerate: { profile: boolean; resumes: boolean };
};

function List({ title, items }: { title: string; items: string[] }) {
    if (!items.length) {
        return null;
    }

    return (
        <div>
            <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                {title}
            </div>
            <ul className="list-disc space-y-0.5 pl-5 text-sm">
                {items.map((i) => (
                    <li key={i}>{i}</li>
                ))}
            </ul>
        </div>
    );
}

export default function ShowJob({
    job,
    analysis,
    match,
    resumeComparison,
    sources,
    countries,
    outsideTargets,
    canGenerate,
}: Props) {
    const [editing, setEditing] = useState(false);
    const ready = canGenerate.profile && canGenerate.resumes;
    const analyzing =
        job.analysis_state === 'pending' || job.analysis_state === 'running';
    const { start, stop } = usePoll(
        3000,
        { only: ['job', 'analysis', 'match'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (analyzing) {
            start();
        } else {
            stop();
        }
    }, [analyzing, start, stop]);

    return (
        <>
            <Head title={job.position ?? 'Job'} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
                {outsideTargets && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        {job.country?.name} isn't one of your target countries.
                        You can still apply, or update your targets in your
                        career profile.
                    </p>
                )}
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={job.position ?? 'Untitled position'}
                        description={[
                            job.company,
                            job.location,
                            job.country?.name,
                            job.source,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    />
                    <div className="flex gap-2">
                        {job.url && (
                            <Button variant="outline" asChild>
                                <a
                                    href={job.url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink /> Posting
                                </a>
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            onClick={() => setEditing((e) => !e)}
                        >
                            {editing ? 'Close editor' : 'Edit'}
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            asChild
                            aria-label="Delete job"
                        >
                            <Link
                                href={JobPostingController.destroy(job.id)}
                                as="button"
                                onBefore={() =>
                                    confirm(
                                        'Delete this job and its application?',
                                    )
                                }
                            >
                                <Trash2 />
                            </Link>
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                        <div className="grid gap-1.5">
                            <CardTitle>How well it fits you</CardTitle>
                            <CardDescription>
                                Required skills you can back up with your
                                profile or resumes.
                            </CardDescription>
                        </div>
                        {ready && !analyzing && (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={JobPostingController.analyze(job.id)}
                                    as="button"
                                    preserveScroll
                                >
                                    <RefreshCw />{' '}
                                    {match ? 'Re-analyze' : 'Analyze'}
                                </Link>
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <FitBadge match={match} state={job.analysis_state} />
                        {job.analysis_state === 'failed' &&
                            job.analysis_error && (
                                <p className="text-sm text-red-600 dark:text-red-400">
                                    {job.analysis_error}
                                </p>
                            )}
                        {match && (
                            <>
                                {match.stale && (
                                    <p className="text-sm text-muted-foreground">
                                        Your profile or resumes changed since
                                        this was checked. Re-analyze to update
                                        it.
                                    </p>
                                )}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                            You have
                                        </div>
                                        <div className="flex flex-wrap gap-1">
                                            {match.matching_skills.length ? (
                                                match.matching_skills.map(
                                                    (s) => (
                                                        <Badge key={s}>
                                                            {s}
                                                        </Badge>
                                                    ),
                                                )
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    None
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                            Missing
                                        </div>
                                        <div className="flex flex-wrap gap-1">
                                            {match.missing_skills.length ? (
                                                match.missing_skills.map(
                                                    (s) => (
                                                        <Badge
                                                            key={s}
                                                            variant="outline"
                                                        >
                                                            {s}
                                                        </Badge>
                                                    ),
                                                )
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    None
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <RequirementChecks
                                    checks={match.requirement_checks ?? []}
                                />
                                {match.recommended_resume && (
                                    <p className="text-sm">
                                        Best resume:{' '}
                                        <span className="font-medium">
                                            {match.recommended_resume}
                                        </span>
                                        {match.reason && (
                                            <span className="text-muted-foreground">
                                                . {match.reason}
                                            </span>
                                        )}
                                    </p>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>

                <ResumeComparison rows={resumeComparison} />

                <Card>
                    <CardHeader>
                        <CardTitle>Application</CardTitle>
                        <CardDescription>
                            JobHunter analyzes the job, picks your best existing
                            resume, and drafts a personalized email for you to
                            review.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {job.application ? (
                            <div className="flex items-center gap-3">
                                <StatusBadge
                                    status={job.application.status}
                                    generationState={
                                        job.application.generation_state
                                    }
                                />
                                <Button asChild>
                                    <Link
                                        href={ApplicationController.show(
                                            job.application.id,
                                        )}
                                    >
                                        Open application
                                    </Link>
                                </Button>
                            </div>
                        ) : (
                            <Form {...ApplicationController.store.form(job.id)}>
                                {({ processing, errors }) => (
                                    <div className="grid gap-2">
                                        <div>
                                            <Button
                                                disabled={processing || !ready}
                                            >
                                                <Sparkles /> Generate
                                                application
                                            </Button>
                                        </div>
                                        {!ready && (
                                            <p className="text-sm text-muted-foreground">
                                                {!canGenerate.profile &&
                                                    'Add your career profile. '}
                                                {!canGenerate.resumes &&
                                                    'Upload at least one active resume.'}
                                            </p>
                                        )}
                                        <InputError
                                            message={
                                                (
                                                    errors as Record<
                                                        string,
                                                        string
                                                    >
                                                ).application
                                            }
                                        />
                                    </div>
                                )}
                            </Form>
                        )}
                    </CardContent>
                </Card>

                {editing && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Edit job</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...JobPostingController.update.form(job.id)}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setEditing(false)}
                                className="flex flex-col gap-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <JobFormFields
                                            job={job}
                                            sources={sources}
                                            countries={countries}
                                            errors={errors}
                                        />
                                        <div>
                                            <Button disabled={processing}>
                                                Save
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Job description</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap">
                                {job.description}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Job analysis</CardTitle>
                            <CardDescription>
                                {analysis
                                    ? 'Extracted from the posting only.'
                                    : 'Runs when you generate an application.'}
                            </CardDescription>
                        </CardHeader>
                        {analysis && (
                            <CardContent className="grid gap-4">
                                {analysis.seniority && (
                                    <div className="text-sm">
                                        Seniority:{' '}
                                        <span className="capitalize">
                                            {analysis.seniority}
                                        </span>
                                    </div>
                                )}
                                {[
                                    [
                                        'Required skills',
                                        analysis.required_skills,
                                        'default',
                                    ],
                                    [
                                        'Preferred skills',
                                        analysis.preferred_skills,
                                        'secondary',
                                    ],
                                ].map(([title, skills, variant]) =>
                                    (skills as string[]).length ? (
                                        <div key={title as string}>
                                            <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                                {title as string}
                                            </div>
                                            <div className="flex flex-wrap gap-1">
                                                {(skills as string[]).map(
                                                    (s) => (
                                                        <Badge
                                                            key={s}
                                                            variant={
                                                                variant as
                                                                    | 'default'
                                                                    | 'secondary'
                                                            }
                                                        >
                                                            {s}
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    ) : null,
                                )}
                                <List
                                    title="Requirements"
                                    items={analysis.requirements}
                                />
                                <List
                                    title="Responsibilities"
                                    items={analysis.responsibilities}
                                />
                            </CardContent>
                        )}
                    </Card>
                </div>
            </div>
        </>
    );
}

ShowJob.layout = {
    breadcrumbs: [{ title: 'Jobs', href: JobPostingController.index() }],
};

const CHECK_LABEL: Record<RequirementCheck['status'], string> = {
    met: 'Met',
    partial: 'Partly',
    not_met: 'Not shown',
    not_applicable: 'Not needed',
};

/** Requirements beyond skills (degree, years, duties), each with the exact words from your profile that back it. */
function RequirementChecks({ checks }: { checks: RequirementCheck[] }) {
    const shown = checks.filter((c) => c.status !== 'not_applicable');
    if (!shown.length) {
        return null;
    }

    return (
        <div>
            <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                Requirements
            </div>
            <ul className="space-y-2 text-sm">
                {shown.map((c) => (
                    <li key={c.requirement}>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant={
                                    c.status === 'met' ? 'default' : 'outline'
                                }
                            >
                                {CHECK_LABEL[c.status]}
                            </Badge>
                            <span>{c.requirement}</span>
                            {!c.required && (
                                <span className="text-xs text-muted-foreground">
                                    preferred
                                </span>
                            )}
                        </div>
                        {c.evidence.map((e) => (
                            <p
                                key={e.quote}
                                className="mt-1 ml-1 border-l-2 pl-2 text-muted-foreground"
                            >
                                {e.source}: “{e.quote}”
                            </p>
                        ))}
                        {c.note && !c.evidence.length && (
                            <p className="mt-1 ml-1 text-xs text-muted-foreground">
                                {c.note}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
