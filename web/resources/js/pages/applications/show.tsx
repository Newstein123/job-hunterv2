import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { AlertTriangle, Loader2, RefreshCw, X } from 'lucide-react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import JobPostingController from '@/actions/App/Http/Controllers/JobPostingController';
import RecipientsCard from '@/components/applications/recipients-card';
import Conversation from '@/components/applications/conversation';
import SendPanel from '@/components/applications/send-panel';
import type { SendCan } from '@/components/applications/send-panel';
import Field from '@/components/field';
import CheckReplies from '@/components/check-replies';
import Heading from '@/components/heading';
import ReplyStateLine from '@/components/reply-state';
import StatusBadge from '@/components/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import type {
    ApplicationDetail,
    EmailAccount,
    Option,
    SkillMatch,
} from '@/types';

type Props = {
    application: ApplicationDetail;
    resumes: { id: number; label: string; original_filename: string }[];
    can: SendCan & {
        edit: boolean;
        editRecipients: boolean;
        resend: boolean;
        regenerate: boolean;
        withdraw: boolean;
    };
    accounts: EmailAccount[];
    trackingOptions: Option[];
};

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 py-2 sm:grid-cols-[180px_1fr] sm:gap-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

function Chips({
    items,
    variant = 'default',
}: {
    items: string[];
    variant?: 'default' | 'secondary' | 'outline';
}) {
    if (!items.length) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {items.map((s) => (
                <Badge key={s} variant={variant}>
                    {s}
                </Badge>
            ))}
        </div>
    );
}

function evidenceLabel(ref: string): string {
    const [kind] = ref.split(':');

    return (
        {
            skill_list: 'skills list',
            experience: 'experience',
            project: 'project',
            resume: 'resume',
        }[kind] ?? kind
    );
}

function MatchList({
    title,
    matches,
}: {
    title: string;
    matches: SkillMatch[];
}) {
    return (
        <div>
            <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                {title}
            </div>
            {matches.length === 0 ? (
                <p className="text-sm text-muted-foreground">None</p>
            ) : (
                <ul className="grid gap-1 text-sm">
                    {matches.map((m) => (
                        <li key={m.skill}>
                            <span className="font-medium">{m.skill}</span>{' '}
                            <span className="text-xs text-muted-foreground">
                                (
                                {[
                                    ...new Set(m.evidence.map(evidenceLabel)),
                                ].join(', ')}
                                )
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function ShowApplication({
    application: app,
    resumes,
    can,
    trackingOptions,
    accounts,
}: Props) {
    const generating =
        app.generation_state === 'pending' ||
        app.generation_state === 'running';
    const { start, stop } = usePoll(
        3000,
        { only: ['application', 'can', 'trackingOptions', 'accounts'] },
        { autoStart: false },
    );

    // Poll while something is about to change: generation, or a send that is due now.
    const inFlight =
        generating ||
        ['queued', 'sending'].includes(app.outbound?.status ?? '') ||
        app.conversation.some(
            (c) =>
                c.analysis_state === 'pending' ||
                c.analysis_state === 'running' ||
                (c.kind === 'answer' && c.body === null && !c.last_error) ||
                (c.kind === 'answer' &&
                    ['queued', 'sending'].includes(c.status)),
        );

    useEffect(() => {
        if (inFlight) {
            start();
        } else {
            stop();
        }
    }, [inFlight, start, stop]);

    const s = app.strategy;
    const errors = app.validation.errors;
    const needsAck = errors.length > 0 && !app.edited_at;
    const title = app.job.position ?? 'Untitled position';
    const sendingAccount =
        app.outbound?.from_email ??
        accounts.find((a) => a.id === app.email_account_id)?.email;

    return (
        <>
            <Head title={`Review: ${title}`} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Heading
                            title={title}
                            description={[app.job.company, app.job.country]
                                .filter(Boolean)
                                .join(' · ')}
                        />
                        <div className="-mt-6">
                            <StatusBadge
                                status={app.status}
                                generationState={app.generation_state}
                            />
                            <ReplyStateLine
                                state={app.reply_state}
                                className="ml-2"
                            />
                            <div className="mt-2">
                                <CheckReplies />
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={JobPostingController.show(app.job.id)}>
                                View job
                            </Link>
                        </Button>
                        {can.regenerate && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={ApplicationController.regenerate(
                                        app.id,
                                    )}
                                    as="button"
                                    preserveScroll
                                    onBefore={() =>
                                        !app.edited_at ||
                                        confirm(
                                            'Regenerating discards your edits. Continue?',
                                        )
                                    }
                                >
                                    <RefreshCw /> Regenerate
                                </Link>
                            </Button>
                        )}
                        {can.withdraw && (
                            <Button variant="ghost" asChild>
                                <Link
                                    href={ApplicationController.withdraw(
                                        app.id,
                                    )}
                                    as="button"
                                    preserveScroll
                                    onBefore={() =>
                                        confirm('Cancel this application?')
                                    }
                                >
                                    <X /> Cancel application
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {generating && (
                    <Alert>
                        <Loader2 className="animate-spin" />
                        <AlertTitle>Preparing your application…</AlertTitle>
                        <AlertDescription>
                            Analyzing the job, matching your profile, choosing a
                            resume and drafting the email. This page updates
                            automatically.
                        </AlertDescription>
                    </Alert>
                )}

                {app.generation_state === 'failed' && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>Generation failed</AlertTitle>
                        <AlertDescription>
                            {app.generation_error ?? 'Please try again.'}
                        </AlertDescription>
                    </Alert>
                )}

                {s && app.subject !== null && (
                    <>
                        <Card>
                            <CardHeader>
                                <CardTitle>Review before sending</CardTitle>
                                <CardDescription>
                                    Nothing is sent without your approval. Check
                                    the resume choice and every claim in the
                                    email.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <dl className="divide-y">
                                    <Row label="Company">
                                        {app.job.company ?? '—'}
                                    </Row>
                                    <Row label="Position">{title}</Row>
                                    <Row label="Recommended resume">
                                        <span className="font-medium">
                                            {
                                                s.resume_selection
                                                    .recommended_filename
                                            }
                                        </span>
                                        {app.resume &&
                                            String(app.resume.id) !==
                                                s.resume_selection
                                                    .recommended_resume_id && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    (you switched to{' '}
                                                    {app.resume.label})
                                                </span>
                                            )}
                                    </Row>
                                    <Row label="Why this resume">
                                        {s.resume_selection.reason}
                                    </Row>
                                    <Row label="Skills emphasized">
                                        <Chips
                                            items={s.skill_strategy.emphasize}
                                        />
                                    </Row>
                                    <Row label="Supporting skills">
                                        <Chips
                                            items={
                                                s.skill_strategy
                                                    .supporting_skills
                                            }
                                            variant="secondary"
                                        />
                                    </Row>
                                    <Row label="Not claimed (missing)">
                                        <Chips
                                            items={
                                                s.skill_strategy.do_not_claim
                                            }
                                            variant="outline"
                                        />
                                    </Row>
                                    <Row label="Attachment">
                                        {app.resume?.original_filename ?? '—'}
                                    </Row>
                                    <Row label="Earliest send time">
                                        {formatDate(
                                            app.earliest_send_at,
                                            true,
                                            s.application_strategy
                                                .send_timezone,
                                        )}{' '}
                                        <span className="text-muted-foreground">
                                            (allowed window,{' '}
                                            {
                                                s.application_strategy
                                                    .send_timezone
                                            }
                                            )
                                        </span>
                                    </Row>
                                    <Row label="Sending account">
                                        {sendingAccount ?? (
                                            <span className="text-muted-foreground">
                                                Chosen when you approve
                                            </span>
                                        )}
                                    </Row>
                                    <Row label="To">
                                        {app.job.contact_email ?? (
                                            <span className="text-muted-foreground">
                                                No contact email on this job
                                            </span>
                                        )}
                                    </Row>
                                    {(app.cc?.length ?? 0) > 0 && (
                                        <Row label="CC">
                                            {app.cc?.join(', ')}
                                        </Row>
                                    )}
                                    {(app.bcc?.length ?? 0) > 0 && (
                                        <Row label="BCC">
                                            {app.bcc?.join(', ')}
                                        </Row>
                                    )}
                                </dl>
                            </CardContent>
                        </Card>

                        {(errors.length > 0 ||
                            app.validation.warnings.length > 0) && (
                            <Alert
                                variant={
                                    errors.length ? 'destructive' : 'default'
                                }
                            >
                                <AlertTriangle />
                                <AlertTitle>
                                    {errors.length
                                        ? 'Check these issues before approving'
                                        : 'Things to double-check'}
                                </AlertTitle>
                                <AlertDescription>
                                    <ul className="list-disc pl-5">
                                        {[
                                            ...errors,
                                            ...app.validation.warnings,
                                        ].map((e) => (
                                            <li key={e}>{e}</li>
                                        ))}
                                    </ul>
                                    {app.edited_at && errors.length > 0 && (
                                        <p className="mt-1">
                                            You edited this draft after these
                                            checks ran.
                                        </p>
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}

                        <Card>
                            <CardHeader>
                                <CardTitle>Email</CardTitle>
                                {!can.edit && (
                                    <CardDescription>
                                        {app.status === 'approved'
                                            ? 'Approved. Reopen it to make changes.'
                                            : 'This application can no longer be edited.'}
                                    </CardDescription>
                                )}
                            </CardHeader>
                            <CardContent>
                                <Form
                                    {...ApplicationController.update.form(
                                        app.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                    className="grid gap-4"
                                >
                                    {({
                                        processing,
                                        errors: formErrors,
                                        isDirty,
                                    }) => (
                                        <fieldset
                                            disabled={!can.edit}
                                            className="grid gap-4"
                                        >
                                            <Field
                                                label="Resume to attach"
                                                htmlFor="resume_id"
                                                error={formErrors.resume_id}
                                            >
                                                <NativeSelect
                                                    id="resume_id"
                                                    name="resume_id"
                                                    defaultValue={
                                                        app.resume_id ?? ''
                                                    }
                                                >
                                                    {resumes.map((r) => (
                                                        <option
                                                            key={r.id}
                                                            value={r.id}
                                                        >
                                                            {r.label} (
                                                            {
                                                                r.original_filename
                                                            }
                                                            )
                                                            {String(r.id) ===
                                                            s.resume_selection
                                                                .recommended_resume_id
                                                                ? ', recommended'
                                                                : ''}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </Field>
                                            <Field
                                                label="Subject"
                                                htmlFor="subject"
                                                error={formErrors.subject}
                                            >
                                                <Input
                                                    id="subject"
                                                    name="subject"
                                                    defaultValue={
                                                        app.subject ?? ''
                                                    }
                                                    required
                                                />
                                            </Field>
                                            <Field
                                                label="Body"
                                                htmlFor="body"
                                                error={formErrors.body}
                                            >
                                                <Textarea
                                                    id="body"
                                                    name="body"
                                                    rows={16}
                                                    defaultValue={
                                                        app.body ?? ''
                                                    }
                                                    required
                                                />
                                            </Field>
                                            {(app.cover_letter ||
                                                s.application_strategy
                                                    .include_cover_letter) && (
                                                <Field
                                                    label="Cover letter"
                                                    htmlFor="cover_letter"
                                                    hint={
                                                        s.application_strategy
                                                            .cover_letter_reason
                                                    }
                                                    error={
                                                        formErrors.cover_letter
                                                    }
                                                >
                                                    <Textarea
                                                        id="cover_letter"
                                                        name="cover_letter"
                                                        rows={14}
                                                        defaultValue={
                                                            app.cover_letter ??
                                                            ''
                                                        }
                                                    />
                                                </Field>
                                            )}
                                            {can.edit && (
                                                <div>
                                                    <Button
                                                        variant="outline"
                                                        disabled={
                                                            processing ||
                                                            !isDirty
                                                        }
                                                    >
                                                        Save changes
                                                    </Button>
                                                </div>
                                            )}
                                        </fieldset>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>

                        {can.editRecipients && (
                            <RecipientsCard
                                app={app}
                                accounts={accounts}
                                mode={
                                    can.resend
                                        ? 'resend'
                                        : can.retrySend
                                          ? 'retry'
                                          : 'save'
                                }
                            />
                        )}
                        <SendPanel
                            app={app}
                            accounts={accounts}
                            can={can}
                            needsAck={needsAck}
                        />
                        <Conversation
                            app={app}
                            accounts={accounts}
                            trackingOptions={trackingOptions}
                        />
                    </>
                )}

                {trackingOptions.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Track progress</CardTitle>
                            <CardDescription>
                                Record what happened with this application.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...ApplicationController.track.form(app.id)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-3 sm:grid-cols-[200px_1fr_auto] sm:items-end"
                            >
                                {({ processing, errors: formErrors }) => (
                                    <>
                                        <Field
                                            label="New status"
                                            htmlFor="status"
                                            error={formErrors.status}
                                        >
                                            <NativeSelect
                                                id="status"
                                                name="status"
                                                required
                                            >
                                                {trackingOptions.map((o) => (
                                                    <option
                                                        key={o.value}
                                                        value={o.value}
                                                    >
                                                        {o.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </Field>
                                        <Field
                                            label="Note (optional)"
                                            htmlFor="note"
                                            error={formErrors.note}
                                        >
                                            <Input
                                                id="note"
                                                name="note"
                                                placeholder="e.g. Sent from my Gmail"
                                            />
                                        </Field>
                                        <Button
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Update
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                )}

                {s && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Match evidence</CardTitle>
                            <CardDescription>
                                Covers {s.match.summary.required_covered}/
                                {s.match.summary.required_total} required and{' '}
                                {s.match.summary.preferred_covered}/
                                {s.match.summary.preferred_total} preferred
                                skills, each backed by your profile or resumes.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-6 md:grid-cols-2">
                            <MatchList
                                title="Matching required skills"
                                matches={s.match.matching_required}
                            />
                            <MatchList
                                title="Matching preferred skills"
                                matches={s.match.matching_preferred}
                            />
                            <div>
                                <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                    Missing
                                </div>
                                <Chips
                                    items={[
                                        ...s.match.missing_required,
                                        ...s.match.missing_preferred,
                                    ]}
                                    variant="outline"
                                />
                            </div>
                            <div>
                                <div className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                    Relevant experience & projects
                                </div>
                                <ul className="grid gap-1 text-sm">
                                    {[
                                        ...s.match.relevant_experience,
                                        ...s.match.relevant_projects,
                                    ].map((r) => (
                                        <li key={r.id}>
                                            {r.label}
                                            {r.matched_skills.length > 0 && (
                                                <span className="text-xs text-muted-foreground">
                                                    {' '}
                                                    (
                                                    {r.matched_skills.join(
                                                        ', ',
                                                    )}
                                                    )
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <p className="text-xs text-muted-foreground md:col-span-2">
                                {s.meta.model} ·{' '}
                                {Object.values(s.meta.prompt_versions).join(
                                    ', ',
                                )}
                            </p>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="grid gap-2 text-sm">
                            {app.history.map((h) => (
                                <li
                                    key={h.id}
                                    className="flex flex-wrap gap-x-2"
                                >
                                    <span className="text-muted-foreground">
                                        {formatDate(h.created_at, true)}
                                    </span>
                                    <span className="font-medium capitalize">
                                        {h.from_status
                                            ? `${h.from_status} → ${h.to_status}`
                                            : h.to_status}
                                    </span>
                                    {h.note && (
                                        <span className="text-muted-foreground">
                                            · {h.note}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ShowApplication.layout = {
    breadcrumbs: [
        { title: 'Applications', href: ApplicationController.index() },
    ],
};
