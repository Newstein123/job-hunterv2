import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useState } from 'react';
import JobImportController from '@/actions/App/Http/Controllers/JobImportController';
import JobPostingController from '@/actions/App/Http/Controllers/JobPostingController';
import Field from '@/components/field';
import Heading from '@/components/heading';
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
import ReviewController from '@/actions/App/Http/Controllers/ReviewController';

const columns = [
    ['description', 'required: the full job description'],
    ['position', 'or "title"'],
    ['company', ''],
    ['url', 'or "job url"; used to detect duplicates'],
    ['location', ''],
    [
        'country',
        'name, code or city alias, e.g. UAE, Dubai, AU, Singapore, Thailand',
    ],
    ['contact email', 'only if the posting lists one'],
    ['cc', 'extra addresses to copy, separated by commas'],
    ['source', 'e.g. LinkedIn'],
];

const companyColumns = [
    ['email', 'required: the company address to write to'],
    ['company name', 'or "company"'],
    ['headquarter', 'or "country"; must be a supported country'],
    ['website', ''],
    ['industry', 'used to personalize the email'],
    ['specialties', 'comma-separated; used to personalize the email'],
    ['source', 'e.g. The Grid'],
];

export default function ImportJobs({
    maxRows,
    resumes,
}: {
    maxRows: number;
    resumes: { id: number; label: string }[];
}) {
    const result = usePage().flash.importResult;
    const [kind, setKind] = useState<'posting' | 'speculative'>('posting');
    const shown = kind === 'posting' ? columns : companyColumns;

    return (
        <>
            <Head title="Import jobs" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <Heading
                    title="Import jobs"
                    description={`Upload a CSV or Excel (.xlsx) file with one job per row (up to ${maxRows}). Each row is still reviewed individually before anything is sent.`}
                />

                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant={kind === 'posting' ? 'default' : 'outline'}
                        onClick={() => setKind('posting')}
                    >
                        Job posts
                    </Button>
                    <Button
                        type="button"
                        variant={kind === 'speculative' ? 'default' : 'outline'}
                        onClick={() => setKind('speculative')}
                    >
                        Companies (speculative)
                    </Button>
                </div>
                {kind === 'speculative' && (
                    <p className="text-sm text-muted-foreground">
                        One row per company that hasn't posted a job. You choose
                        the role you're offering and the resume to send; an
                        email is drafted for each company from your profile and
                        what the list says about the company. You review them
                        all on one page before anything is sent. One company per
                        email domain; at most 20 of these are sent per account
                        per day.
                    </p>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Columns</CardTitle>
                        <CardDescription>
                            The first row must be a header. Column names are
                            case-insensitive.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="grid gap-1 text-sm">
                            {shown.map(([name, note]) => (
                                <li key={name}>
                                    <code className="rounded bg-muted px-1">
                                        {name}
                                    </code>
                                    {note && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            {note}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>

                <Form
                    {...JobImportController.store.form()}
                    resetOnSuccess
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="kind" value={kind} />
                            {kind === 'speculative' && (
                                <>
                                    <Field
                                        label="Position you're offering"
                                        htmlFor="position"
                                        error={errors.position}
                                        className="min-w-56 flex-1"
                                    >
                                        <Input
                                            id="position"
                                            name="position"
                                            placeholder="Senior Web Developer"
                                            required
                                        />
                                    </Field>
                                    <Field
                                        label="Resume to send"
                                        htmlFor="resume_id"
                                        error={errors.resume_id}
                                        className="min-w-56 flex-1"
                                    >
                                        <NativeSelect
                                            id="resume_id"
                                            name="resume_id"
                                            required
                                        >
                                            {resumes.map((r) => (
                                                <option key={r.id} value={r.id}>
                                                    {r.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                </>
                            )}
                            <Field
                                label="File"
                                htmlFor="file"
                                error={errors.file}
                                className="min-w-64 flex-1"
                            >
                                <Input
                                    id="file"
                                    name="file"
                                    type="file"
                                    accept=".csv,.xlsx"
                                    required
                                />
                            </Field>
                            <Button disabled={processing}>
                                <Upload /> Import
                            </Button>
                        </>
                    )}
                </Form>

                {result && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Import result</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2 text-sm">
                            <p>
                                <strong>{result.imported}</strong> job(s)
                                imported.
                            </p>
                            {result.analysis === 'queued' && (
                                <p>
                                    Each job is now being checked against your
                                    profile.{' '}
                                    <Link
                                        href={JobPostingController.index({
                                            query: { sort: 'fit' },
                                        })}
                                        className="underline"
                                    >
                                        See which jobs fit best
                                    </Link>
                                </p>
                            )}
                            {result.analysis === 'drafting' && (
                                <p>
                                    Emails are being drafted for each company.{' '}
                                    <Link
                                        href={ReviewController.index()}
                                        className="underline"
                                    >
                                        Review them
                                    </Link>
                                </p>
                            )}
                            {result.analysis === 'needs_profile' && (
                                <p className="text-muted-foreground">
                                    Add your career profile and an active
                                    resume, then use “Analyze all” on the Jobs
                                    page to see how well each job fits.
                                </p>
                            )}
                            {result.duplicates.length > 0 && (
                                <p className="text-muted-foreground">
                                    Skipped duplicates on row(s):{' '}
                                    {result.duplicates.join(', ')}
                                </p>
                            )}
                            {Object.keys(result.unsupported_countries ?? {})
                                .length > 0 && (
                                <div>
                                    <p className="font-medium text-amber-600 dark:text-amber-400">
                                        Skipped: country not supported (
                                        {Object.values(
                                            result.unsupported_countries,
                                        ).reduce(
                                            (n, rows) => n + rows.length,
                                            0,
                                        )}{' '}
                                        rows)
                                    </p>
                                    <p className="text-muted-foreground">
                                        {Object.entries(
                                            result.unsupported_countries,
                                        )
                                            .sort(
                                                (a, b) =>
                                                    b[1].length - a[1].length,
                                            )
                                            .map(
                                                ([country, rows]) =>
                                                    `${country || 'No country'} (${rows.length})`,
                                            )
                                            .join(', ')}
                                        . Supported:{' '}
                                        {result.supported.join(', ')}.
                                    </p>
                                </div>
                            )}
                            {result.errors.length > 0 && (
                                <div>
                                    <p className="font-medium text-red-600 dark:text-red-400">
                                        Rows with errors:
                                    </p>
                                    <ul className="list-disc pl-5">
                                        {result.errors.map((e) => (
                                            <li key={e.row}>
                                                Row {e.row}:{' '}
                                                {e.messages.join(' ')}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

ImportJobs.layout = {
    breadcrumbs: [
        { title: 'Jobs', href: JobPostingController.index() },
        { title: 'Import', href: JobImportController.create() },
    ],
};
