import { Form, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    Clock,
    MailCheck,
    Send,
    Undo2,
    X,
} from 'lucide-react';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import EmailAccountController from '@/actions/App/Http/Controllers/EmailAccountController';
import InputError from '@/components/input-error';
import SendTimeTips from '@/components/send-time-tips';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { NativeSelect } from '@/components/ui/native-select';
import { formatDate } from '@/lib/format';
import type { ApplicationDetail, EmailAccount } from '@/types';

export type SendCan = {
    approve: boolean;
    reopen: boolean;
    queue: boolean;
    cancelSend: boolean;
    retrySend: boolean;
};

const messageStatus: Record<string, string> = {
    queued: 'Queued. Sending shortly',
    scheduled: 'Scheduled',
    sending: 'Sending now…',
    retrying: 'Temporary problem. Will retry',
    failed: 'Failed',
    sent: 'Sent',
    cancelled: 'Cancelled',
};

function AccountSelect({
    accounts,
    allowNone,
    defaultId,
}: {
    accounts: EmailAccount[];
    allowNone?: boolean;
    defaultId?: number | null;
}) {
    const active = accounts.filter((a) => a.status === 'active');
    const fallback =
        defaultId ??
        active.find((a) => a.is_default)?.id ??
        active[0]?.id ??
        '';

    return (
        <NativeSelect
            name="email_account_id"
            defaultValue={fallback}
            className="sm:w-80"
            aria-label="Send from"
        >
            {allowNone && <option value="">Don't send. Approve only</option>}
            {accounts.map((a) => (
                <option
                    key={a.id}
                    value={a.id}
                    disabled={a.status !== 'active'}
                >
                    {a.email}
                    {a.is_default ? ' (default)' : ''}
                    {a.status !== 'active' ? ': unavailable' : ''}
                </option>
            ))}
        </NativeSelect>
    );
}

export default function SendPanel({
    app,
    accounts,
    can,
    needsAck,
}: {
    app: ApplicationDetail;
    accounts: EmailAccount[];
    can: SendCan;
    needsAck: boolean;
}) {
    const out = app.outbound;
    const hasRecipient = Boolean(app.job.contact_email);
    const hasActive = accounts.some((a) => a.status === 'active');
    const canSend = hasRecipient && hasActive;
    const err = (errors: Record<string, string | undefined>) =>
        errors.send ??
        errors.recipient ??
        errors.email_account_id ??
        errors.resume_id ??
        errors.acknowledge;

    const prerequisites = !hasRecipient ? (
        <p className="text-sm text-muted-foreground">
            No contact email on this job, so it can't be sent from JobHunter.
            Add the address the posting gives on the{' '}
            <Link href={`/jobs/${app.job.id}`} className="underline">
                job page
            </Link>
            , or send it yourself and mark it as sent.
        </p>
    ) : !hasActive ? (
        <p className="text-sm text-muted-foreground">
            <Link href={EmailAccountController.index()} className="underline">
                Connect a Gmail account
            </Link>{' '}
            to send from JobHunter, or send it yourself and mark it as sent.
        </p>
    ) : null;

    if (can.approve) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Approve</CardTitle>
                    <CardDescription>
                        Approving locks this draft.{' '}
                        <strong>Approve &amp; send</strong> sends it now
                        {app.job.contact_email
                            ? ` (to ${app.job.contact_email})`
                            : ''}
                        . <strong>Approve only</strong> keeps it unsent so you
                        can schedule it later from the Jobs list, e.g. at 06:30
                        in the job's country time.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-3">
                    <SendTimeTips />
                    <Form
                        {...ApplicationController.approve.form(app.id)}
                        options={{ preserveScroll: true }}
                        onBefore={() =>
                            !needsAck ||
                            confirm(
                                'This draft still has validation issues. Approve it anyway?',
                            )
                        }
                        className="flex flex-wrap items-center gap-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="acknowledge"
                                    value={needsAck ? '1' : '0'}
                                />
                                {canSend && (
                                    <>
                                        <AccountSelect accounts={accounts} />
                                        <Button disabled={processing}>
                                            <Send /> Approve &amp; send
                                        </Button>
                                    </>
                                )}
                                {/* Approve without queueing: schedule it later from the Jobs list. */}
                                <Button
                                    name="approve_only"
                                    value="1"
                                    variant={canSend ? 'outline' : 'default'}
                                    disabled={processing}
                                >
                                    <Check /> Approve only
                                </Button>
                                <InputError
                                    className="basis-full"
                                    message={err(
                                        errors as Record<string, string>,
                                    )}
                                />
                            </>
                        )}
                    </Form>
                    {prerequisites}
                </CardContent>
            </Card>
        );
    }

    if (app.status === 'approved') {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Approved</CardTitle>
                    <CardDescription>Not sent yet.</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-3">
                    {can.queue && canSend && (
                        <Form
                            {...ApplicationController.queue.form(app.id)}
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-center gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <AccountSelect accounts={accounts} />
                                    <Button disabled={processing}>
                                        <Send /> Queue for sending
                                    </Button>
                                    <InputError
                                        className="basis-full"
                                        message={err(
                                            errors as Record<string, string>,
                                        )}
                                    />
                                </>
                            )}
                        </Form>
                    )}
                    {prerequisites}
                    {can.reopen && (
                        <div>
                            <Button variant="outline" asChild>
                                <Link
                                    href={ApplicationController.reopen(app.id)}
                                    as="button"
                                    preserveScroll
                                >
                                    <Undo2 /> Reopen for editing
                                </Link>
                            </Button>
                        </div>
                    )}
                </CardContent>
            </Card>
        );
    }

    if (!out || out.status === 'cancelled') {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    {out.status === 'sent' ? (
                        <MailCheck className="size-5" />
                    ) : (
                        <Clock className="size-5" />
                    )}
                    {messageStatus[out.status] ?? out.status}
                </CardTitle>
                <CardDescription>
                    From {out.from_email} to {out.to_email}
                    {out.cc?.length ? ` · CC ${out.cc.join(', ')}` : ''}
                    {out.bcc?.length ? ` · BCC ${out.bcc.join(', ')}` : ''}
                    {out.status === 'sent'
                        ? ` · ${formatDate(out.sent_at, true)}`
                        : out.scheduled_at
                          ? ` · not before ${formatDate(out.scheduled_at, true)}`
                          : ''}
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3">
                {out.last_error && (
                    <Alert
                        variant={
                            out.status === 'retrying'
                                ? 'default'
                                : 'destructive'
                        }
                    >
                        <AlertTriangle />
                        <AlertTitle>
                            {out.status === 'sent'
                                ? 'Delivery problem'
                                : 'Sending problem'}
                        </AlertTitle>
                        <AlertDescription>{out.last_error}</AlertDescription>
                    </Alert>
                )}
                {can.retrySend && (
                    <Form
                        {...ApplicationController.retrySend.form(app.id)}
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-center gap-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <AccountSelect accounts={accounts} />
                                <Button disabled={processing || !hasActive}>
                                    <Send /> Retry
                                </Button>
                                <InputError
                                    className="basis-full"
                                    message={err(
                                        errors as Record<string, string>,
                                    )}
                                />
                            </>
                        )}
                    </Form>
                )}
                {can.cancelSend && (
                    <div>
                        <Button variant="outline" asChild>
                            <Link
                                href={ApplicationController.cancelSend(app.id)}
                                as="button"
                                preserveScroll
                                onBefore={() =>
                                    confirm(
                                        'Cancel sending? The application stays approved.',
                                    )
                                }
                            >
                                <X /> Cancel sending
                            </Link>
                        </Button>
                    </div>
                )}
                {app.replies.length > 0 && (
                    <div className="text-sm">
                        <div className="mb-1 font-medium">Replies</div>
                        <ul className="grid gap-1">
                            {app.replies.map((r) => (
                                <li key={r.id}>
                                    {r.from_email}{' '}
                                    <span className="text-muted-foreground">
                                        · {formatDate(r.received_at, true)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
