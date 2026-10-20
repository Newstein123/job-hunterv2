import { Form } from '@inertiajs/react';
import { RefreshCw, Save, Send } from 'lucide-react';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import Field from '@/components/field';
import InputError from '@/components/input-error';
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
import type { ApplicationDetail, EmailAccount } from '@/types';

/**
 * To / CC / BCC, editable whenever nothing is mid-send, so a delivery problem can be fixed
 * in place. The approved subject and body are not touched here.
 */
export default function RecipientsCard({
    app,
    accounts,
    mode,
}: {
    app: ApplicationDetail;
    accounts: Pick<EmailAccount, 'id' | 'email' | 'status' | 'is_default'>[];
    /** what the main button does after saving */
    mode: 'save' | 'retry' | 'resend';
}) {
    const out = app.outbound;
    const active = accounts.filter((a) => a.status === 'active');
    const defaultAccount =
        active.find((a) => a.email === out?.from_email)?.id ??
        active.find((a) => a.is_default)?.id ??
        active[0]?.id;
    const problem = mode === 'retry' || mode === 'resend';

    return (
        <Card className={problem ? 'border-destructive/50' : undefined}>
            <CardHeader>
                <CardTitle>Recipients</CardTitle>
                <CardDescription>
                    {mode === 'resend'
                        ? 'The email bounced. Correct the address and send it again.'
                        : mode === 'retry'
                          ? 'Sending failed. Fix the addresses or pick another account, then retry.'
                          : 'Every address is checked when you save: format, test domains, mail servers and past bounces.'}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    {...ApplicationController.updateRecipients.form(app.id)}
                    options={{ preserveScroll: true }}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => {
                        const e = errors as Record<string, string | undefined>;
                        const first = (field: string) =>
                            e[field] ??
                            Object.entries(e).find(([k]) =>
                                k.startsWith(`${field}.`),
                            )?.[1];

                        return (
                            <>
                                <Field
                                    label="To"
                                    htmlFor="rcpt-to"
                                    error={first('to')}
                                >
                                    <Input
                                        id="rcpt-to"
                                        name="to"
                                        type="email"
                                        required
                                        defaultValue={
                                            out?.to_email ??
                                            app.job.contact_email ??
                                            ''
                                        }
                                        placeholder="careers@company.com"
                                    />
                                </Field>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="CC"
                                        htmlFor="rcpt-cc"
                                        hint="Separate several with commas."
                                        error={first('cc')}
                                    >
                                        <Input
                                            id="rcpt-cc"
                                            name="cc"
                                            defaultValue={
                                                app.cc?.join(', ') ?? ''
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="BCC"
                                        htmlFor="rcpt-bcc"
                                        hint="Hidden from the other recipients."
                                        error={first('bcc')}
                                    >
                                        <Input
                                            id="rcpt-bcc"
                                            name="bcc"
                                            defaultValue={
                                                app.bcc?.join(', ') ?? ''
                                            }
                                        />
                                    </Field>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {problem && active.length > 0 && (
                                        <NativeSelect
                                            name="email_account_id"
                                            defaultValue={defaultAccount}
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
                                    {problem ? (
                                        <>
                                            <Button
                                                name="then"
                                                value={mode}
                                                disabled={
                                                    processing ||
                                                    active.length === 0
                                                }
                                            >
                                                {mode === 'retry' ? (
                                                    <RefreshCw />
                                                ) : (
                                                    <Send />
                                                )}
                                                {mode === 'retry'
                                                    ? 'Save & retry'
                                                    : 'Save & resend'}
                                            </Button>
                                            <Button
                                                name="then"
                                                value="save"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Save only
                                            </Button>
                                        </>
                                    ) : (
                                        <Button
                                            name="then"
                                            value="save"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            <Save /> Save recipients
                                        </Button>
                                    )}
                                </div>
                                <InputError
                                    message={
                                        e.email_account_id ??
                                        e.send ??
                                        e.recipient
                                    }
                                />
                            </>
                        );
                    }}
                </Form>
            </CardContent>
        </Card>
    );
}
