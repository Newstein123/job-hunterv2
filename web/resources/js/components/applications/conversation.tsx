import { Form, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ExternalLink,
    Loader2,
    Mail,
    RefreshCw,
    Send,
    Sparkles,
    Trash2,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import ReplyController from '@/actions/App/Http/Controllers/ReplyController';
import Field from '@/components/field';
import InputError from '@/components/input-error';
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
import { cn } from '@/lib/utils';
import type {
    ApplicationDetail,
    ConversationItem,
    EmailAccount,
    Option,
    ReplyCategory,
} from '@/types';

const categoryLabel: Record<ReplyCategory, string> = {
    interview_invite: 'Interview invite',
    info_request: 'Asks for information',
    rejection: 'Rejection',
    offer: 'Offer',
    acknowledgement: 'Acknowledgement',
    auto_reply: 'Automatic reply',
    other: 'Other',
};

const categoryTone: Record<ReplyCategory, string> = {
    interview_invite:
        'bg-fuchsia-100 text-fuchsia-800 dark:bg-fuchsia-950 dark:text-fuchsia-200',
    info_request:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    rejection: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
    offer: 'bg-green-200 text-green-900 dark:bg-green-900 dark:text-green-100',
    acknowledgement:
        'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    auto_reply: 'bg-muted text-muted-foreground',
    other: 'bg-muted text-muted-foreground',
};

type Accounts = Pick<EmailAccount, 'id' | 'email' | 'status' | 'is_default'>[];

function Body({ text, full }: { text: string; full?: string | null }) {
    const [open, setOpen] = useState(false);
    const hasMore = full && full.trim() !== text.trim();

    return (
        <div className="grid gap-1">
            <p className="text-sm whitespace-pre-wrap">
                {open && full ? full : text}
            </p>
            {hasMore && (
                <button
                    type="button"
                    className="w-fit text-xs text-muted-foreground underline"
                    onClick={() => setOpen(!open)}
                >
                    {open ? 'Hide earlier messages' : 'Show earlier messages'}
                </button>
            )}
        </div>
    );
}

function List({ title, items }: { title: string; items: string[] }) {
    if (!items.length) {
        return null;
    }

    return (
        <div>
            <div className="text-xs font-medium text-muted-foreground uppercase">
                {title}
            </div>
            <ul className="list-disc pl-5 text-sm">
                {items.map((i) => (
                    <li key={i}>{i}</li>
                ))}
            </ul>
        </div>
    );
}

function AnalysisPanel({
    item,
    app,
    trackingOptions,
}: {
    item: ConversationItem;
    app: ApplicationDetail;
    trackingOptions: Option[];
}) {
    if (
        item.analysis_state === 'pending' ||
        item.analysis_state === 'running'
    ) {
        return (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" /> Reading the reply…
            </p>
        );
    }
    if (item.analysis_state === 'failed' || (!item.analysis && item.body)) {
        return (
            <div className="flex flex-wrap items-center gap-2 text-sm">
                {item.analysis_error && (
                    <span className="text-red-600 dark:text-red-400">
                        {item.analysis_error}
                    </span>
                )}
                <Button size="sm" variant="outline" asChild>
                    <Link
                        href={ReplyController.analyze(item.id)}
                        as="button"
                        preserveScroll
                    >
                        <RefreshCw />{' '}
                        {item.analysis_error ? 'Try again' : 'Analyze reply'}
                    </Link>
                </Button>
            </div>
        );
    }
    const a = item.analysis;
    if (!a) {
        return null;
    }
    const suggestion = a.suggested_status
        ? trackingOptions.find((o) => o.value === a.suggested_status)
        : undefined;

    return (
        <div className="grid gap-3 rounded-lg border bg-muted/30 p-3">
            <div className="flex flex-wrap items-center gap-2">
                <Sparkles className="size-4 text-muted-foreground" />
                <Badge
                    variant="outline"
                    className={cn(
                        'border-transparent',
                        categoryTone[a.category],
                    )}
                >
                    {categoryLabel[a.category]}
                </Badge>
                <span className="text-sm">{a.summary}</span>
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
                <List title="They ask for" items={a.requests} />
                <List title="Questions" items={a.questions} />
                <List title="Dates mentioned" items={a.dates_mentioned} />
            </div>
            {a.evidence_quotes.length > 0 && (
                <div className="grid gap-1">
                    {a.evidence_quotes.map((q) => (
                        <blockquote
                            key={q}
                            className="border-l-2 pl-2 text-xs text-muted-foreground italic"
                        >
                            “{q}”
                        </blockquote>
                    ))}
                </div>
            )}
            {a.warnings.map((w) => (
                <p
                    key={w}
                    className="text-xs text-amber-700 dark:text-amber-400"
                >
                    {w}
                </p>
            ))}
            {suggestion && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span>Suggested stage:</span>
                    <Button size="sm" asChild>
                        <Link
                            href={ApplicationController.track(app.id)}
                            data={{
                                status: suggestion.value,
                                note: `From reply: ${categoryLabel[a.category].toLowerCase()}`,
                            }}
                            as="button"
                            preserveScroll
                        >
                            Mark as {suggestion.label}
                        </Link>
                    </Button>
                    <span className="text-xs text-muted-foreground">
                        Only changes when you click.
                    </span>
                </div>
            )}
        </div>
    );
}

function DraftRequest({
    reply,
    regenerate,
}: {
    reply: ConversationItem;
    regenerate?: boolean;
}) {
    return (
        <Form
            {...ReplyController.draft.form(reply.id)}
            options={{ preserveScroll: true }}
            className="grid gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <Field
                        label={
                            regenerate
                                ? 'Notes for a new draft'
                                : 'Notes for your reply (optional)'
                        }
                        htmlFor={`notes-${reply.id}`}
                        hint="Facts the AI doesn't have, e.g. “Available Tue/Wed 2–5 pm SGT, notice period 1 month”. It only uses your profile and these notes."
                        error={errors.notes}
                    >
                        <Textarea
                            id={`notes-${reply.id}`}
                            name="notes"
                            rows={2}
                        />
                    </Field>
                    <div>
                        <Button
                            size="sm"
                            variant={regenerate ? 'outline' : 'default'}
                            disabled={processing}
                        >
                            <Sparkles />{' '}
                            {regenerate ? 'Draft again' : 'Draft a reply'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function AnswerDraft({
    item,
    reply,
    accounts,
}: {
    item: ConversationItem;
    reply?: ConversationItem;
    accounts: Accounts;
}) {
    const active = accounts.filter((a) => a.status === 'active');
    const errors = item.validation?.errors ?? [];
    const needs = item.validation?.needs_input ?? [];

    if (item.body === null) {
        return item.last_error ? (
            <div className="grid gap-2">
                <p className="text-sm text-red-600 dark:text-red-400">
                    {item.last_error}
                </p>
                {reply && <DraftRequest reply={reply} regenerate />}
            </div>
        ) : (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" /> Drafting your reply…
            </p>
        );
    }

    return (
        <div className="grid gap-3 rounded-lg border p-3">
            <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium">Your reply (draft)</span>
                <Button
                    size="icon"
                    variant="ghost"
                    asChild
                    aria-label="Discard draft"
                >
                    <Link
                        href={ReplyController.destroy(item.id)}
                        as="button"
                        preserveScroll
                        onBefore={() => confirm('Discard this draft?')}
                    >
                        <Trash2 />
                    </Link>
                </Button>
            </div>
            {(errors.length > 0 || needs.length > 0) && (
                <Alert variant="destructive">
                    <AlertTriangle />
                    <AlertTitle>Before sending</AlertTitle>
                    <AlertDescription>
                        <ul className="list-disc pl-5">
                            {errors.map((e) => (
                                <li key={e}>{e}</li>
                            ))}
                            {needs.map((n) => (
                                <li key={n}>
                                    You need to add:{' '}
                                    {n.replace(/^\[\[|\]\]$/g, '')}
                                </li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}
            <Form
                {...ReplyController.update.form(item.id)}
                options={{ preserveScroll: true }}
                className="grid gap-3"
            >
                {({ processing, errors: fe }) => (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                label="To"
                                htmlFor={`to-${item.id}`}
                                error={fe.to_email}
                            >
                                <Input
                                    id={`to-${item.id}`}
                                    name="to_email"
                                    defaultValue={item.to ?? ''}
                                    required
                                />
                            </Field>
                            <Field
                                label="CC"
                                htmlFor={`cc-${item.id}`}
                                error={fe.cc}
                            >
                                <Input
                                    id={`cc-${item.id}`}
                                    name="cc"
                                    defaultValue={item.cc?.join(', ') ?? ''}
                                />
                            </Field>
                        </div>
                        <Field
                            label="Subject"
                            htmlFor={`subj-${item.id}`}
                            error={fe.subject}
                        >
                            <Input
                                id={`subj-${item.id}`}
                                name="subject"
                                defaultValue={item.subject ?? ''}
                                required
                            />
                        </Field>
                        <Field
                            label="Message"
                            htmlFor={`body-${item.id}`}
                            hint="Replace every [[...]] with your own answer."
                            error={fe.body_text}
                        >
                            <Textarea
                                id={`body-${item.id}`}
                                name="body_text"
                                rows={10}
                                defaultValue={item.body ?? ''}
                                required
                            />
                        </Field>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="hidden"
                                name="attach_resume"
                                value="0"
                            />
                            <input
                                type="checkbox"
                                name="attach_resume"
                                value="1"
                                defaultChecked={item.attach_resume}
                            />
                            Attach my resume again
                        </label>
                        <div>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                Save draft
                            </Button>
                        </div>
                    </>
                )}
            </Form>
            <Form
                {...ReplyController.send.form(item.id)}
                options={{ preserveScroll: true }}
                className="flex flex-wrap items-center gap-2"
            >
                {({ processing, errors: se }) => (
                    <>
                        {active.length > 0 && (
                            <NativeSelect
                                name="email_account_id"
                                defaultValue={
                                    active.find((a) => a.email === item.from)
                                        ?.id ??
                                    active.find((a) => a.is_default)?.id ??
                                    active[0].id
                                }
                                className="sm:w-72"
                                aria-label="Send from"
                            >
                                {active.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        From {a.email}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                        <Button
                            size="sm"
                            disabled={processing || active.length === 0}
                        >
                            <Send /> Approve &amp; send reply
                        </Button>
                        <InputError
                            className="basis-full"
                            message={
                                se.body ??
                                se.recipient ??
                                se.email_account_id ??
                                se.send
                            }
                        />
                        <p className="basis-full text-xs text-muted-foreground">
                            Save your edits first. The reply goes into the same
                            Gmail thread inside your sending window.
                        </p>
                    </>
                )}
            </Form>
            {reply && <DraftRequest reply={reply} regenerate />}
        </div>
    );
}

export default function Conversation({
    app,
    accounts,
    trackingOptions,
}: {
    app: ApplicationDetail;
    accounts: Accounts;
    trackingOptions: Option[];
}) {
    const items = app.conversation;
    if (!items.some((i) => i.kind === 'reply')) {
        return null;
    }
    const answersTo = (id: number) =>
        items.filter((i) => i.kind === 'answer' && i.in_reply_to_id === id);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Mail className="size-5" /> Conversation
                </CardTitle>
                <CardDescription>
                    Replies from the employer, what they ask for, and your
                    answers. The AI suggests the stage and drafts answers; you
                    decide and approve.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-5">
                {items
                    .filter((i) => i.kind !== 'answer')
                    .map((item) => (
                        <div
                            key={item.id}
                            className="grid gap-3 border-l-2 pl-4"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span>
                                    <span className="font-medium">
                                        {item.kind === 'application'
                                            ? 'You'
                                            : item.is_bounce
                                              ? 'Gmail'
                                              : item.from}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        ·{' '}
                                        {item.kind === 'application'
                                            ? 'sent the application'
                                            : item.is_bounce
                                              ? 'delivery failed'
                                              : item.is_auto_reply
                                                ? 'automatic reply'
                                                : 'replied'}{' '}
                                        · {formatDate(item.at, true)}
                                    </span>
                                </span>
                                <span className="flex items-center gap-2">
                                    {item.is_auto_reply && (
                                        <Badge variant="secondary">
                                            Automatic reply, not counted
                                        </Badge>
                                    )}
                                    {item.is_bounce && (
                                        <Badge variant="destructive">
                                            Not delivered
                                        </Badge>
                                    )}
                                    {item.gmail_url && (
                                        <a
                                            href={item.gmail_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1 text-xs underline"
                                        >
                                            Open in Gmail{' '}
                                            <ExternalLink className="size-3" />
                                        </a>
                                    )}
                                </span>
                            </div>

                            {item.is_bounce && (
                                <Alert variant="destructive">
                                    <AlertTriangle />
                                    <AlertTitle>
                                        Your email didn't reach{' '}
                                        {app.outbound?.to_email ??
                                            app.job.contact_email ??
                                            'the recipient'}
                                    </AlertTitle>
                                    <AlertDescription>
                                        Gmail returned it, usually because the
                                        address doesn't exist or the domain
                                        can't receive mail. The employer never
                                        saw it. Correct the address in{' '}
                                        <strong>Recipients</strong> above and
                                        choose{' '}
                                        <strong>Save &amp; resend</strong>.
                                    </AlertDescription>
                                </Alert>
                            )}

                            {item.kind === 'application' ? (
                                <details className="text-sm">
                                    <summary className="cursor-pointer text-muted-foreground">
                                        {item.subject}
                                    </summary>
                                    <p className="mt-2 whitespace-pre-wrap">
                                        {item.body}
                                    </p>
                                </details>
                            ) : item.body ? (
                                <Body
                                    text={item.latest_text ?? item.body}
                                    full={item.body}
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {item.snippet ??
                                        (item.is_bounce
                                            ? ''
                                            : item.can_read
                                              ? 'Loading the text of this message on the next mailbox check (within 10 minutes).'
                                              : 'The text isn’t available.')}{' '}
                                    {!item.can_read &&
                                        'Reconnect this Gmail account on the Email accounts page to read replies here.'}
                                </p>
                            )}

                            {item.kind === 'reply' &&
                                !item.is_auto_reply &&
                                !item.is_bounce && (
                                    <>
                                        <AnalysisPanel
                                            item={item}
                                            app={app}
                                            trackingOptions={trackingOptions}
                                        />
                                        {answersTo(item.id).map((answer) =>
                                            answer.status === 'draft' ? (
                                                <AnswerDraft
                                                    key={answer.id}
                                                    item={answer}
                                                    reply={item}
                                                    accounts={accounts}
                                                />
                                            ) : (
                                                <div
                                                    key={answer.id}
                                                    className="grid gap-2 rounded-lg border p-3"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                                        <span>
                                                            <span className="font-medium">
                                                                You
                                                            </span>
                                                            <span className="text-muted-foreground">
                                                                {' '}
                                                                ·{' '}
                                                                {answer.status ===
                                                                'sent'
                                                                    ? `replied · ${formatDate(answer.at, true)}`
                                                                    : answer.status ===
                                                                        'failed'
                                                                      ? 'reply failed'
                                                                      : `reply ${answer.status}${answer.scheduled_at ? ` · not before ${formatDate(answer.scheduled_at, true)}` : ''}`}
                                                            </span>
                                                        </span>
                                                        {answer.status !==
                                                            'sent' && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={ReplyController.cancel(
                                                                        answer.id,
                                                                    )}
                                                                    as="button"
                                                                    preserveScroll
                                                                >
                                                                    <Undo2 />{' '}
                                                                    Back to
                                                                    draft
                                                                </Link>
                                                            </Button>
                                                        )}
                                                    </div>
                                                    {answer.last_error && (
                                                        <p className="text-sm text-red-600 dark:text-red-400">
                                                            {answer.last_error}
                                                        </p>
                                                    )}
                                                    <p className="text-sm whitespace-pre-wrap">
                                                        {answer.body}
                                                    </p>
                                                </div>
                                            ),
                                        )}
                                        {item.analysis &&
                                            answersTo(item.id).length === 0 && (
                                                <DraftRequest reply={item} />
                                            )}
                                    </>
                                )}
                        </div>
                    ))}
            </CardContent>
        </Card>
    );
}
