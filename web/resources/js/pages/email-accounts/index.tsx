import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle, Mail, Plus, Star, Trash2 } from 'lucide-react';
import EmailAccountController from '@/actions/App/Http/Controllers/EmailAccountController';
import Heading from '@/components/heading';
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
import { formatDate } from '@/lib/format';
import type { EmailAccount, EmailAccountStatus } from '@/types';

const statusLabel: Record<EmailAccountStatus, string> = {
    active: 'Active',
    needs_reauth: 'Reconnect needed',
    restricted: 'Restricted by provider',
    disabled: 'Disabled',
};

type Props = {
    accounts: EmailAccount[];
    googleConfigured: boolean;
    maxDailyLimit: number;
};

export default function EmailAccounts({
    accounts,
    googleConfigured,
    maxDailyLimit,
}: Props) {
    const connectUrl = EmailAccountController.redirectToGoogle.url();

    return (
        <>
            <Head title="Email accounts" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Email accounts"
                        description="Connect the mailboxes you apply from. Each account has its own daily limit; JobHunter never rotates accounts to get around provider limits."
                    />
                    {googleConfigured && (
                        <Button asChild>
                            {/* Full-page redirect to Google, not an Inertia visit */}
                            <a href={connectUrl}>
                                <Plus /> Connect Gmail
                            </a>
                        </Button>
                    )}
                </div>

                {!googleConfigured && (
                    <Alert>
                        <AlertTriangle />
                        <AlertTitle>Google sign-in isn't configured</AlertTitle>
                        <AlertDescription>
                            Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in
                            web/.env (see README), then reload.
                        </AlertDescription>
                    </Alert>
                )}

                {accounts.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        No accounts connected. You can still approve
                        applications and send them yourself.
                    </div>
                ) : (
                    accounts.map((account) => (
                        <Card key={account.id}>
                            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                                <div className="grid gap-1">
                                    <CardTitle className="flex items-center gap-2">
                                        <Mail className="size-4" />{' '}
                                        {account.email}
                                        {account.is_default && (
                                            <Badge variant="secondary">
                                                Default
                                            </Badge>
                                        )}
                                        <Badge
                                            variant={
                                                account.status === 'active'
                                                    ? 'outline'
                                                    : 'destructive'
                                            }
                                        >
                                            {statusLabel[account.status]}
                                        </Badge>
                                    </CardTitle>
                                    <CardDescription>
                                        Gmail · sent today{' '}
                                        {account.sent_today ?? 0}/
                                        {account.daily_limit} ·{' '}
                                        {account.queued ?? 0} queued · last
                                        reply check{' '}
                                        {formatDate(
                                            account.last_synced_at,
                                            true,
                                        )}
                                    </CardDescription>
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    {!account.is_default && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={EmailAccountController.update(
                                                    account.id,
                                                )}
                                                data={{ is_default: true }}
                                                as="button"
                                                preserveScroll
                                            >
                                                <Star /> Make default
                                            </Link>
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        asChild
                                        aria-label="Disconnect"
                                    >
                                        <Link
                                            href={EmailAccountController.destroy(
                                                account.id,
                                            )}
                                            as="button"
                                            preserveScroll
                                            onBefore={() =>
                                                confirm(
                                                    `Disconnect ${account.email}? JobHunter will lose access to it.`,
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Link>
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent className="grid gap-4">
                                {account.status === 'active' &&
                                    account.can_read === false && (
                                        <Alert>
                                            <AlertTriangle />
                                            <AlertTitle>
                                                Reconnect to read replies
                                            </AlertTitle>
                                            <AlertDescription>
                                                <p>
                                                    This account was connected
                                                    before JobHunter could read
                                                    replies. It still sends and
                                                    detects replies, but can't
                                                    show or analyze their text.
                                                </p>
                                                {googleConfigured && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="mt-2 w-fit"
                                                        asChild
                                                    >
                                                        <a href={connectUrl}>
                                                            Reconnect
                                                        </a>
                                                    </Button>
                                                )}
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                {account.status !== 'active' && (
                                    <Alert variant="destructive">
                                        <AlertTriangle />
                                        <AlertTitle>
                                            JobHunter stopped using this account
                                        </AlertTitle>
                                        <AlertDescription>
                                            <p>{account.status_reason}</p>
                                            <div className="mt-2 flex gap-2">
                                                {account.status ===
                                                    'needs_reauth' &&
                                                    googleConfigured && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <a
                                                                href={
                                                                    connectUrl
                                                                }
                                                            >
                                                                Reconnect
                                                            </a>
                                                        </Button>
                                                    )}
                                                {account.status ===
                                                    'restricted' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={EmailAccountController.update(
                                                                account.id,
                                                            )}
                                                            data={{
                                                                resume: true,
                                                            }}
                                                            as="button"
                                                            preserveScroll
                                                            onBefore={() =>
                                                                confirm(
                                                                    'Only resume once the provider has lifted the restriction. Resume?',
                                                                )
                                                            }
                                                        >
                                                            Resume sending
                                                        </Link>
                                                    </Button>
                                                )}
                                            </div>
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <Form
                                    {...EmailAccountController.update.form(
                                        account.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                    className="flex flex-wrap items-end gap-3"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <div className="grid gap-2">
                                                <label
                                                    htmlFor={`limit-${account.id}`}
                                                    className="text-sm font-medium"
                                                >
                                                    Daily sending limit
                                                </label>
                                                <Input
                                                    id={`limit-${account.id}`}
                                                    name="daily_limit"
                                                    type="number"
                                                    min={1}
                                                    max={maxDailyLimit}
                                                    defaultValue={
                                                        account.daily_limit
                                                    }
                                                    className="w-32"
                                                />
                                                <InputError
                                                    message={errors.daily_limit}
                                                />
                                            </div>
                                            <Button
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Save
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>
                    ))
                )}

                <p className="text-xs text-muted-foreground">
                    JobHunter asks Google for permission to send email and to
                    read your mailbox. It only opens the threads of applications
                    you sent, to show, classify and answer the employer's
                    replies.
                </p>
            </div>
        </>
    );
}

EmailAccounts.layout = {
    breadcrumbs: [
        { title: 'Email accounts', href: EmailAccountController.index() },
    ],
};
