import { Form } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { useState } from 'react';
import JobBulkController from '@/actions/App/Http/Controllers/JobBulkController';
import Field from '@/components/field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import type { EmailAccount } from '@/types';

const MARKET_TIMEZONES = [
    'Asia/Dubai',
    'Asia/Bangkok',
    'Asia/Singapore',
    'Australia/Sydney',
    'Australia/Perth',
    'UTC',
];

function defaultSendAt(): string {
    // Tomorrow 09:30 local, formatted for <input type="datetime-local">.
    const d = new Date();
    d.setDate(d.getDate() + 1);
    d.setHours(9, 30, 0, 0);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function ScheduleDialog({
    open,
    onOpenChange,
    jobIds,
    approvedCount,
    accounts,
    onDone,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobIds: number[];
    approvedCount: number;
    accounts: Pick<EmailAccount, 'id' | 'email' | 'status' | 'is_default'>[];
    onDone: () => void;
}) {
    const [mode, setMode] = useState<'earliest' | 'at'>('earliest');
    const [tzMode, setTzMode] = useState<'country' | 'fixed'>('country');
    const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const timezones = [...new Set([browserTz, ...MARKET_TIMEZONES])];
    const active = accounts.filter((a) => a.status === 'active');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Schedule send</DialogTitle>
                    <DialogDescription>
                        {approvedCount} of {jobIds.length} selected job(s) are
                        approved and will be scheduled. The rest are skipped and
                        listed afterwards. Emails from one account go out about
                        2 minutes apart.
                    </DialogDescription>
                </DialogHeader>
                {active.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Connect a Gmail account on the Email accounts page
                        first.
                    </p>
                ) : (
                    <Form
                        {...JobBulkController.schedule.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => {
                            onOpenChange(false);
                            onDone();
                        }}
                        className="grid gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                {jobIds.map((id) => (
                                    <input
                                        key={id}
                                        type="hidden"
                                        name="job_ids[]"
                                        value={id}
                                    />
                                ))}
                                <Field
                                    label="Send from"
                                    htmlFor="bulk-account"
                                    error={errors.email_account_id}
                                >
                                    <NativeSelect
                                        id="bulk-account"
                                        name="email_account_id"
                                        defaultValue={
                                            active.find((a) => a.is_default)
                                                ?.id ?? active[0].id
                                        }
                                    >
                                        {active.map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.email}
                                                {a.is_default
                                                    ? ' (default)'
                                                    : ''}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <fieldset className="grid gap-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        When
                                    </legend>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="mode"
                                            value="earliest"
                                            checked={mode === 'earliest'}
                                            onChange={() => setMode('earliest')}
                                        />
                                        Earliest time each job's sending window
                                        allows
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="mode"
                                            value="at"
                                            checked={mode === 'at'}
                                            onChange={() => setMode('at')}
                                        />
                                        At a specific time
                                    </label>
                                </fieldset>
                                {mode === 'at' && (
                                    <div className="grid gap-4">
                                        <Field
                                            label="Date and time"
                                            htmlFor="bulk-send-at"
                                            error={errors.send_at}
                                        >
                                            <Input
                                                id="bulk-send-at"
                                                name="send_at"
                                                type="datetime-local"
                                                defaultValue={defaultSendAt()}
                                                required
                                            />
                                        </Field>
                                        <fieldset className="grid gap-2">
                                            <legend className="mb-1 text-sm font-medium">
                                                In which timezone
                                            </legend>
                                            <label className="flex items-start gap-2 text-sm">
                                                <input
                                                    type="radio"
                                                    name="timezone_mode"
                                                    value="country"
                                                    checked={
                                                        tzMode === 'country'
                                                    }
                                                    onChange={() =>
                                                        setTzMode('country')
                                                    }
                                                    className="mt-1"
                                                />
                                                <span>
                                                    Each job's country time
                                                    <span className="block text-xs text-muted-foreground">
                                                        06:30 means 06:30 in
                                                        Bangkok for Thailand
                                                        jobs, in Sydney for
                                                        Australia, in Singapore
                                                        and in Dubai.
                                                    </span>
                                                </span>
                                            </label>
                                            <label className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="radio"
                                                    name="timezone_mode"
                                                    value="fixed"
                                                    checked={tzMode === 'fixed'}
                                                    onChange={() =>
                                                        setTzMode('fixed')
                                                    }
                                                />
                                                One timezone for all
                                            </label>
                                            {tzMode === 'fixed' && (
                                                <NativeSelect
                                                    id="bulk-tz"
                                                    name="timezone"
                                                    defaultValue={browserTz}
                                                    aria-label="Timezone"
                                                >
                                                    {timezones.map((tz) => (
                                                        <option
                                                            key={tz}
                                                            value={tz}
                                                        >
                                                            {tz}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            )}
                                            <InputError
                                                message={errors.timezone}
                                            />
                                        </fieldset>
                                        <label className="flex items-start gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                name="keep_window"
                                                value="1"
                                                className="mt-1"
                                            />
                                            <span>
                                                Keep within my sending window
                                                <span className="block text-xs text-muted-foreground">
                                                    Off: sends at exactly this
                                                    time. On: a time outside
                                                    your Rules window moves to
                                                    the next allowed time.
                                                </span>
                                            </span>
                                        </label>
                                    </div>
                                )}
                                <InputError message={errors.job_ids} />
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => onOpenChange(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        disabled={
                                            processing || approvedCount === 0
                                        }
                                    >
                                        <CalendarClock /> Schedule{' '}
                                        {approvedCount} email(s)
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
