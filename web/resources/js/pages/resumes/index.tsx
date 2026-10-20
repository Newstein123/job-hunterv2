import { Form, Head, Link, usePoll } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    CircleDashed,
    Download,
    Eye,
    RefreshCw,
    Sparkles,
    Trash2,
    Upload,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import ResumeController from '@/actions/App/Http/Controllers/ResumeController';
import Field from '@/components/field';
import Heading from '@/components/heading';
import ResumePreviewDialog from '@/components/resume-preview-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatBytes, formatDate } from '@/lib/format';
import type { Resume, ResumeCheck, ResumeInsight } from '@/types';

export default function Resumes({
    resumes,
    insights,
    analyzing,
    reviewing,
    extensions,
}: {
    resumes: Resume[];
    insights: ResumeInsight[];
    analyzing: number;
    reviewing: number;
    extensions: string[];
}) {
    const [viewing, setViewing] = useState<Resume | null>(null);
    const byResume = new Map(insights.map((i) => [i.resume_id, i]));
    // While jobs are being re-checked against the resumes, refresh the results.
    const { start, stop } = usePoll(
        3000,
        { only: ['insights', 'analyzing', 'resumes', 'reviewing'] },
        { autoStart: false },
    );
    useEffect(() => {
        if (analyzing > 0 || reviewing > 0) {
            start();
        } else {
            stop();
        }
    }, [analyzing, reviewing, start, stop]);

    return (
        <>
            <Head title="Resumes" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title="Resumes"
                    description="Upload the resumes you already use (e.g. Backend, Full Stack, Cloud). JobHunter picks the best one per job. It never rewrites them."
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Upload a resume</CardTitle>
                        <CardDescription>
                            {extensions.map((e) => e.toUpperCase()).join(', ')}{' '}
                            up to 5 MB. Scanned image-only PDFs can't be read.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...ResumeController.store.form()}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Field
                                        label="Label"
                                        htmlFor="label"
                                        error={errors.label}
                                    >
                                        <Input
                                            id="label"
                                            name="label"
                                            placeholder="Backend Developer CV"
                                            required
                                        />
                                    </Field>
                                    <Field
                                        label="File"
                                        htmlFor="file"
                                        error={errors.file}
                                    >
                                        <Input
                                            id="file"
                                            name="file"
                                            type="file"
                                            accept={extensions
                                                .map((e) => `.${e}`)
                                                .join(',')}
                                            required
                                        />
                                    </Field>
                                    <Button disabled={processing}>
                                        <Upload /> Upload
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                {analyzing > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Re-checking {analyzing} job(s) against your resumes…
                    </p>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Your resumes</CardTitle>
                        <CardDescription>
                            Only active resumes are considered when generating
                            applications.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {resumes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No resumes yet.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {resumes.map((resume) => (
                                    <li
                                        key={resume.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2 font-medium">
                                                {resume.label}
                                                {!resume.is_active && (
                                                    <Badge variant="secondary">
                                                        Inactive
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {resume.original_filename} ·{' '}
                                                {formatBytes(resume.size)} ·
                                                uploaded{' '}
                                                {formatDate(resume.created_at)}
                                            </div>
                                            <InsightLine
                                                insight={byResume.get(
                                                    resume.id,
                                                )}
                                                active={resume.is_active}
                                                analyzing={analyzing > 0}
                                            />
                                        </div>
                                        <div className="flex gap-1">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setViewing(resume)
                                                }
                                            >
                                                <Eye /> View
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={ResumeController.update(
                                                        resume.id,
                                                    )}
                                                    data={{
                                                        is_active:
                                                            !resume.is_active,
                                                    }}
                                                    as="button"
                                                    preserveScroll
                                                >
                                                    {resume.is_active
                                                        ? 'Deactivate'
                                                        : 'Activate'}
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                                aria-label="Download"
                                            >
                                                <a
                                                    href={ResumeController.download.url(
                                                        resume.id,
                                                    )}
                                                >
                                                    <Download />
                                                </a>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                                aria-label="Delete"
                                            >
                                                <Link
                                                    href={ResumeController.destroy(
                                                        resume.id,
                                                    )}
                                                    as="button"
                                                    preserveScroll
                                                    onBefore={() =>
                                                        confirm(
                                                            `Delete “${resume.label}”?`,
                                                        )
                                                    }
                                                >
                                                    <Trash2 />
                                                </Link>
                                            </Button>
                                        </div>
                                        <CvCheck resume={resume} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
            <ResumePreviewDialog
                resume={viewing}
                onOpenChange={(open) => !open && setViewing(null)}
            />
        </>
    );
}

Resumes.layout = {
    breadcrumbs: [{ title: 'Resumes', href: ResumeController.index() }],
};

/** How this resume does across the jobs you've added. Suggestions only; the resume is never rewritten. */
function InsightLine({
    insight,
    active,
    analyzing,
}: {
    insight: ResumeInsight | undefined;
    active: boolean;
    analyzing: boolean;
}) {
    if (!active) {
        return null;
    }
    if (!insight) {
        return (
            <p className="mt-1 text-xs text-muted-foreground">
                {analyzing
                    ? 'Checking against your jobs…'
                    : 'Not compared with any job yet. Add or analyze a job to see how it fits.'}
            </p>
        );
    }

    return (
        <div className="mt-2 space-y-1 text-xs">
            <p>
                Best resume for{' '}
                <span className="font-medium">
                    {insight.best_for} of {insight.jobs}
                </span>{' '}
                job(s) · covers {insight.avg_required_coverage}% of required
                skills on average
            </p>
            {insight.could_add.length > 0 && (
                <p className="text-muted-foreground">
                    Your profile proves these but this resume doesn&apos;t
                    mention them:{' '}
                    {insight.could_add
                        .map((s) => `${s.skill} (${s.jobs})`)
                        .join(', ')}
                </p>
            )}
            {insight.gaps.length > 0 && (
                <p className="text-muted-foreground">
                    Often asked for, not in your profile:{' '}
                    {insight.gaps
                        .map((s) => `${s.skill} (${s.jobs})`)
                        .join(', ')}
                </p>
            )}
        </div>
    );
}

const STATUS_ICON = {
    pass: (
        <CircleCheck className="size-4 text-emerald-600 dark:text-emerald-400" />
    ),
    missing: <CircleAlert className="size-4 text-red-600 dark:text-red-400" />,
    check: (
        <CircleDashed className="size-4 text-amber-600 dark:text-amber-400" />
    ),
} as const;

const STATUS_WORD: Record<ResumeCheck['status'], string> = {
    pass: 'OK',
    missing: 'Missing',
    check: 'Check',
};

/** CV check after upload: course checklist + AI suggestions. Suggestions only; the file is never changed. */
function CvCheck({ resume }: { resume: Resume }) {
    const recheck = (
        <Button variant="ghost" size="sm" asChild>
            <Link
                href={ResumeController.review(resume.id)}
                as="button"
                preserveScroll
            >
                <RefreshCw /> Check again
            </Link>
        </Button>
    );

    if (
        resume.review_state === 'pending' ||
        resume.review_state === 'running'
    ) {
        return (
            <p className="w-full text-sm text-muted-foreground">
                Checking your CV…
            </p>
        );
    }
    if (resume.review_state === 'failed') {
        return (
            <div className="flex w-full items-center gap-2 text-sm text-red-600 dark:text-red-400">
                {resume.review_error ?? 'The CV check failed.'} {recheck}
            </div>
        );
    }
    if (!resume.review) {
        return <div className="w-full">{recheck}</div>;
    }

    const { checks, suggestions } = resume.review;
    const todo = checks.filter((c) => c.status !== 'pass');
    const passed = checks.filter((c) => c.status === 'pass');

    return (
        <details
            className="w-full rounded-md border px-4 py-3"
            open={todo.length > 0 || suggestions.length > 0}
        >
            <summary className="cursor-pointer text-sm">
                <span className="font-medium">CV check</span>
                <span className="text-muted-foreground">
                    {' '}
                    · {passed.length}/{checks.length} OK
                    {todo.length > 0 && ` · ${todo.length} to fix`}
                    {suggestions.length > 0 &&
                        ` · ${suggestions.length} AI suggestion(s)`}
                </span>
            </summary>
            <div className="mt-3 grid gap-4">
                {todo.length > 0 && (
                    <ul className="grid gap-2">
                        {[...todo]
                            .sort((a, b) =>
                                (a.group ?? 'course').localeCompare(
                                    b.group ?? 'course',
                                ),
                            )
                            .map((c) => (
                                <li key={c.id} className="flex gap-2 text-sm">
                                    <span className="mt-0.5">
                                        {STATUS_ICON[c.status]}
                                    </span>
                                    <span>
                                        <span className="font-medium">
                                            {c.label}
                                        </span>{' '}
                                        <span className="text-xs text-muted-foreground">
                                            ({STATUS_WORD[c.status]}
                                            {c.group === 'ats' &&
                                                ' · job-portal systems (ATS)'}
                                            )
                                        </span>
                                        {c.detail && (
                                            <span className="block text-muted-foreground">
                                                {c.detail}
                                            </span>
                                        )}
                                    </span>
                                </li>
                            ))}
                    </ul>
                )}
                {suggestions.length > 0 && (
                    <div className="grid gap-3">
                        <p className="flex items-center gap-1 text-xs font-medium text-muted-foreground uppercase">
                            <Sparkles className="size-3" /> AI suggestions
                        </p>
                        {suggestions.map((s) => (
                            <div key={s.title} className="text-sm">
                                <p className="font-medium">{s.title}</p>
                                <p className="text-muted-foreground">
                                    {s.detail}
                                </p>
                                {s.quote && (
                                    <p className="mt-1 border-l-2 pl-2 text-muted-foreground">
                                        Your CV: “{s.quote}”
                                    </p>
                                )}
                                {s.example && (
                                    <p className="mt-1 border-l-2 border-emerald-500 pl-2">
                                        e.g. “{s.example}”
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                )}
                {passed.length > 0 && (
                    <p className="text-xs text-muted-foreground">
                        OK: {passed.map((c) => c.label).join(' · ')}
                    </p>
                )}
                <div className="flex items-center justify-between gap-2">
                    <p className="text-xs text-muted-foreground">
                        Suggestions only. JobHunter never edits your CV; upload
                        a new version when you've made changes.
                    </p>
                    {recheck}
                </div>
            </div>
        </details>
    );
}
