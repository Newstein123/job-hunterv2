import { Head, Link, router, usePoll } from '@inertiajs/react';
import { CheckCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import ReviewController from '@/actions/App/Http/Controllers/ReviewController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import type { ContactFlag, ReviewDraft } from '@/types';

const FLAG: Partial<Record<ContactFlag, string>> = {
    other_team: 'Sales/finance/support inbox',
    person: "Looks like a person's address",
};

type Props = {
    kind: 'posting' | 'speculative';
    drafts: ReviewDraft[];
    drafting: number;
    speculativeDailyLimit: number;
};

export default function Review({
    kind,
    drafts,
    drafting,
    speculativeDailyLimit,
}: Props) {
    // Ticked by default, except drafts with unresolved problems (those need opening and fixing).
    const [selected, setSelected] = useState<Set<number>>(
        () => new Set(drafts.filter((d) => !d.errors.length).map((d) => d.id)),
    );
    const [processing, setProcessing] = useState(false);
    const seen = useRef(new Set(drafts.map((d) => d.id)));

    // Drafts that arrive while generation runs start ticked too (unless they have problems).
    useEffect(() => {
        const fresh = drafts.filter((d) => !seen.current.has(d.id));
        fresh.forEach((d) => seen.current.add(d.id));
        const ids = new Set(drafts.map((d) => d.id));
        setSelected(
            (prev) =>
                new Set([
                    ...[...prev].filter((id) => ids.has(id)),
                    ...fresh.filter((d) => !d.errors.length).map((d) => d.id),
                ]),
        );
    }, [drafts]);

    const { start, stop } = usePoll(
        4000,
        { only: ['drafts', 'drafting'] },
        { autoStart: false },
    );
    useEffect(() => {
        if (drafting > 0) {
            start();
        } else {
            stop();
        }
    }, [drafting, start, stop]);

    const toggle = (id: number, on: boolean) =>
        setSelected((prev) => {
            const next = new Set(prev);
            if (on) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });

    const approve = () =>
        router.post(
            ReviewController.approve.url(),
            { application_ids: [...selected] },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <>
            <Head title="Review drafts" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title="Review drafts"
                    description="Read each email, untick any you don't want to send, then approve the rest. Approved emails are scheduled from the Jobs page."
                />

                <div className="flex flex-wrap items-center gap-2">
                    {(['speculative', 'posting'] as const).map((k) => (
                        <Button
                            key={k}
                            variant={kind === k ? 'default' : 'outline'}
                            size="sm"
                            asChild
                        >
                            <Link
                                href={ReviewController.index({
                                    query: { kind: k },
                                })}
                            >
                                {k === 'speculative'
                                    ? 'Companies (speculative)'
                                    : 'Job posts'}
                            </Link>
                        </Button>
                    ))}
                </div>

                {kind === 'speculative' && (
                    <p className="text-sm text-muted-foreground">
                        At most {speculativeDailyLimit} speculative emails are
                        sent per account per day; the rest wait for the next
                        day. Replies to companies that answer are not limited.
                    </p>
                )}
                {drafting > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Drafting {drafting} more email(s)… they appear here as
                        they're ready.
                    </p>
                )}

                {drafts.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing to review right now.
                    </p>
                ) : (
                    <>
                        <div className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-md border bg-background px-3 py-2">
                            <div className="flex items-center gap-3 text-sm">
                                <span>
                                    {selected.size} of {drafts.length} selected
                                </span>
                                <button
                                    type="button"
                                    className="underline"
                                    onClick={() =>
                                        setSelected(
                                            new Set(
                                                drafts
                                                    .filter(
                                                        (d) => !d.errors.length,
                                                    )
                                                    .map((d) => d.id),
                                            ),
                                        )
                                    }
                                >
                                    Select all without problems
                                </button>
                                <button
                                    type="button"
                                    className="underline"
                                    onClick={() => setSelected(new Set())}
                                >
                                    None
                                </button>
                            </div>
                            <Button
                                disabled={processing || selected.size === 0}
                                onClick={approve}
                            >
                                <CheckCheck /> Approve {selected.size}
                            </Button>
                        </div>

                        <ul className="flex flex-col gap-3">
                            {drafts.map((d) => (
                                <DraftCard
                                    key={d.id}
                                    draft={d}
                                    checked={selected.has(d.id)}
                                    onCheck={(on) => toggle(d.id, on)}
                                />
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </>
    );
}

function DraftCard({
    draft,
    checked,
    onCheck,
}: {
    draft: ReviewDraft;
    checked: boolean;
    onCheck: (on: boolean) => void;
}) {
    const flag = draft.contact_flag ? FLAG[draft.contact_flag] : undefined;
    const blocked = draft.errors.length > 0;

    return (
        <li>
            <Card className={checked ? '' : 'opacity-70'}>
                <CardContent className="flex gap-3">
                    <Checkbox
                        className="mt-1"
                        checked={checked}
                        disabled={blocked}
                        onCheckedChange={(v) => onCheck(v === true)}
                        aria-label={`Select ${draft.company ?? 'draft'}`}
                    />
                    <div className="min-w-0 flex-1 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">
                                {draft.company ?? 'Unknown company'}
                            </span>
                            {draft.country && (
                                <span className="text-xs text-muted-foreground">
                                    {draft.country}
                                </span>
                            )}
                            {draft.outside_targets && (
                                <Badge variant="outline">
                                    Not a target country
                                </Badge>
                            )}
                            {flag && <Badge variant="outline">{flag}</Badge>}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            To {draft.to} · {draft.position} · resume:{' '}
                            {draft.resume ?? '—'}
                        </div>
                        <div className="text-sm font-medium">
                            {draft.subject}
                        </div>
                        <details>
                            <summary className="cursor-pointer text-sm text-muted-foreground">
                                Read email
                            </summary>
                            <pre className="mt-2 font-sans text-sm whitespace-pre-wrap">
                                {draft.body}
                            </pre>
                        </details>
                        {blocked && (
                            <div className="text-sm text-red-600 dark:text-red-400">
                                {draft.errors.join(' · ')}
                            </div>
                        )}
                        <Link
                            href={ApplicationController.show(draft.id)}
                            className="text-sm underline"
                        >
                            {blocked ? 'Open to fix' : 'Open to edit'}
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </li>
    );
}
