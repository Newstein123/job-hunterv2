import Field from '@/components/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import type { CountryOption, JobPosting } from '@/types';

/** Fields shared by "Add job" and "Edit job". Only the description is required. */
export default function JobFormFields({
    job,
    sources,
    countries,
    errors,
}: {
    job?: JobPosting;
    sources: string[];
    countries: CountryOption[];
    errors: Record<string, string | undefined>;
}) {
    const text: [keyof JobPosting, string, string, string?][] = [
        ['position', 'Position', 'Senior Backend Engineer'],
        ['company', 'Company', 'Merlion Pay'],
        ['location', 'Location', 'Dubai Marina'],
        ['url', 'Job URL', 'https://…', 'url'],
        ['contact_email', 'Contact email', 'careers@company.com', 'email'],
    ];

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <Field
                label="Job description"
                htmlFor="description"
                error={errors.description}
                hint="Paste the full posting. Position, company and location are extracted if you leave them blank."
                className="sm:col-span-2"
            >
                <Textarea
                    id="description"
                    name="description"
                    rows={12}
                    required
                    defaultValue={job?.description}
                />
            </Field>
            {text.map(([name, label, placeholder, type]) => (
                <Field
                    key={name}
                    label={label}
                    htmlFor={name}
                    error={errors[name]}
                    hint={
                        name === 'contact_email'
                            ? 'Only an address the posting gives. Never a guessed one.'
                            : undefined
                    }
                >
                    <Input
                        id={name}
                        name={name}
                        type={type ?? 'text'}
                        placeholder={placeholder}
                        defaultValue={(job?.[name] as string | null) ?? ''}
                    />
                </Field>
            ))}
            <Field
                label="CC"
                htmlFor="cc_emails"
                error={errors.cc_emails ?? errors['cc_emails.0']}
                hint="Only if the posting asks to copy someone. Separate several with commas."
            >
                <Input
                    id="cc_emails"
                    name="cc_emails"
                    placeholder="hr@company.com"
                    defaultValue={job?.cc_emails?.join(', ') ?? ''}
                />
            </Field>
            <Field
                label="Country"
                htmlFor="country_id"
                error={errors.country_id}
                hint="Sets the country rules and the default sending timezone."
            >
                <NativeSelect
                    id="country_id"
                    name="country_id"
                    defaultValue={job?.country_id ?? ''}
                >
                    <option value="">Not specified</option>
                    {countries.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Field label="Source" htmlFor="source" error={errors.source}>
                <NativeSelect
                    id="source"
                    name="source"
                    defaultValue={job?.source ?? ''}
                >
                    <option value="">—</option>
                    {/* Keep a source that isn't in the list (e.g. from an import) instead of clearing it. */}
                    {(job?.source && !sources.includes(job.source)
                        ? [...sources, job.source]
                        : sources
                    ).map((s) => (
                        <option key={s} value={s}>
                            {s}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
        </div>
    );
}
