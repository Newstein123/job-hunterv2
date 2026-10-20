import { Form, Head } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import CareerProfileController from '@/actions/App/Http/Controllers/CareerProfileController';
import CareerSection, {
    commas,
    lines,
} from '@/components/career/career-section';
import type { FieldDef } from '@/components/career/career-section';
import Field from '@/components/field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import type {
    CareerEducation,
    CareerExperience,
    CareerProfile,
    CareerProject,
    CareerSkill,
    CountryOption,
} from '@/types';

const experienceFields: FieldDef[] = [
    { name: 'title', label: 'Job title', required: true },
    { name: 'company', label: 'Company', required: true },
    { name: 'start_date', label: 'Start date', type: 'date', required: true },
    {
        name: 'end_date',
        label: 'End date',
        type: 'date',
        hint: 'Leave empty if this is your current role',
    },
    { name: 'location', label: 'Location', wide: true },
    { name: 'summary', label: 'Summary', type: 'textarea' },
    {
        name: 'achievements',
        label: 'Achievements',
        type: 'textarea',
        hint: 'One per line',
        toInput: lines,
    },
    {
        name: 'skills',
        label: 'Skills used',
        hint: 'Comma-separated',
        toInput: commas,
        wide: true,
    },
];

const skillFields: FieldDef[] = [
    { name: 'name', label: 'Skill', required: true, wide: true },
    {
        name: 'level',
        label: 'Level',
        type: 'select',
        options: ['beginner', 'intermediate', 'advanced', 'expert'],
    },
    { name: 'years', label: 'Years', type: 'number' },
];

const projectFields: FieldDef[] = [
    { name: 'name', label: 'Project name', required: true },
    { name: 'url', label: 'URL', type: 'url' },
    { name: 'description', label: 'Description', type: 'textarea' },
    {
        name: 'skills',
        label: 'Skills used',
        hint: 'Comma-separated',
        toInput: commas,
        wide: true,
    },
];

const educationFields: FieldDef[] = [
    { name: 'institution', label: 'Institution', required: true, wide: true },
    { name: 'degree', label: 'Degree', required: true },
    { name: 'field', label: 'Field of study' },
    { name: 'start_date', label: 'Start date', type: 'date' },
    { name: 'end_date', label: 'End date', type: 'date' },
];

function Tags({ values }: { values: string[] | null }) {
    if (!values?.length) {
        return null;
    }

    return (
        <div className="mt-1 text-xs text-muted-foreground">
            {values.join(' · ')}
        </div>
    );
}

export default function EditCareerProfile({
    profile,
    defaults,
    countries,
    targetCountryIds,
}: {
    profile: CareerProfile | null;
    defaults: { full_name: string; email: string };
    countries: CountryOption[];
    targetCountryIds: number[];
}) {
    const basic: [keyof CareerProfile, string, string?][] = [
        ['full_name', 'Full name'],
        ['email', 'Email', 'email'],
        ['phone', 'Phone (with country code, e.g. +95 or +65)'],
        ['whatsapp', 'WhatsApp number'],
        ['linkedin', 'LinkedIn URL', 'url'],
        ['location', 'Where you are now (city, country)'],
        ['availability', 'Availability (e.g. Immediately, 1 month notice)'],
        ['headline', 'Headline'],
    ];

    return (
        <>
            <Head title="Career profile" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title="Career profile"
                    description="Your real experience. Applications only claim what is recorded here or in your resumes."
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Basics</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...CareerProfileController.update.form()}
                            options={{ preserveScroll: true }}
                            className="grid gap-4 sm:grid-cols-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    {basic.map(([name, label, type]) => (
                                        <Field
                                            key={name}
                                            label={label}
                                            htmlFor={name}
                                            error={
                                                errors[
                                                    name as keyof typeof errors
                                                ]
                                            }
                                        >
                                            <Input
                                                id={name}
                                                name={name}
                                                type={type ?? 'text'}
                                                required={
                                                    name === 'full_name' ||
                                                    name === 'email'
                                                }
                                                defaultValue={
                                                    (profile?.[name] as
                                                        string | null) ??
                                                    (name === 'full_name' ||
                                                    name === 'email'
                                                        ? defaults[name]
                                                        : '')
                                                }
                                            />
                                        </Field>
                                    ))}
                                    <Field
                                        label="Countries you are applying to"
                                        htmlFor="target_country_ids"
                                        hint="Jobs in other countries are flagged"
                                        error={errors.target_country_ids}
                                        className="sm:col-span-2"
                                    >
                                        <div className="flex flex-wrap gap-4 pt-1">
                                            {countries.map((c) => (
                                                <label
                                                    key={c.id}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <Checkbox
                                                        name="target_country_ids[]"
                                                        value={String(c.id)}
                                                        defaultChecked={targetCountryIds.includes(
                                                            c.id,
                                                        )}
                                                    />
                                                    {c.name}
                                                </label>
                                            ))}
                                        </div>
                                    </Field>
                                    <Field
                                        label="Languages"
                                        htmlFor="languages"
                                        hint="Comma-separated"
                                        error={errors.languages}
                                    >
                                        <Input
                                            id="languages"
                                            name="languages"
                                            defaultValue={
                                                profile?.languages?.join(
                                                    ', ',
                                                ) ?? ''
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Summary"
                                        htmlFor="summary"
                                        error={errors.summary}
                                        className="sm:col-span-2"
                                    >
                                        <Textarea
                                            id="summary"
                                            name="summary"
                                            rows={3}
                                            defaultValue={
                                                profile?.summary ?? ''
                                            }
                                        />
                                    </Field>
                                    <div className="sm:col-span-2">
                                        <Button disabled={processing}>
                                            Save profile
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <CareerSection
                    section="experiences"
                    title="Experience"
                    itemLabel="experience"
                    fields={experienceFields}
                    items={profile?.experiences ?? []}
                    disabled={!profile}
                    render={(item) => {
                        const e = item as unknown as CareerExperience;

                        return (
                            <>
                                <div className="font-medium">
                                    {e.title} · {e.company}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {formatDate(e.start_date)} –{' '}
                                    {e.end_date
                                        ? formatDate(e.end_date)
                                        : 'present'}
                                </div>
                                <Tags values={e.skills} />
                            </>
                        );
                    }}
                />

                <CareerSection
                    section="skills"
                    title="Skills"
                    itemLabel="skill"
                    fields={skillFields}
                    items={profile?.skills ?? []}
                    disabled={!profile}
                    render={(item) => {
                        const s = item as unknown as CareerSkill;

                        return (
                            <span>
                                <span className="font-medium">{s.name}</span>
                                <span className="text-muted-foreground">
                                    {[
                                        s.level,
                                        s.years ? `${s.years} yrs` : null,
                                    ]
                                        .filter(Boolean)
                                        .map((v) => ` · ${v}`)}
                                </span>
                            </span>
                        );
                    }}
                />

                <CareerSection
                    section="projects"
                    title="Projects"
                    itemLabel="project"
                    fields={projectFields}
                    items={profile?.projects ?? []}
                    disabled={!profile}
                    render={(item) => {
                        const p = item as unknown as CareerProject;

                        return (
                            <>
                                <div className="font-medium">{p.name}</div>
                                {p.description && (
                                    <div className="text-muted-foreground">
                                        {p.description}
                                    </div>
                                )}
                                <Tags values={p.skills} />
                            </>
                        );
                    }}
                />

                <CareerSection
                    section="education"
                    title="Education"
                    itemLabel="education entry"
                    fields={educationFields}
                    items={profile?.education ?? []}
                    disabled={!profile}
                    render={(item) => {
                        const e = item as unknown as CareerEducation;

                        return (
                            <>
                                <div className="font-medium">
                                    {e.degree}
                                    {e.field ? `, ${e.field}` : ''}
                                </div>
                                <div className="text-muted-foreground">
                                    {e.institution}
                                </div>
                            </>
                        );
                    }}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Import from JSON</CardTitle>
                        <CardDescription>
                            Upload a candidate_profile.json (the AI service's
                            format). This replaces your current profile entries.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...CareerProfileController.import.form()}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            onBefore={() =>
                                !profile ||
                                confirm(
                                    'Replace your current profile with the imported one?',
                                )
                            }
                            className="flex flex-wrap items-start gap-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-1">
                                        <Input
                                            type="file"
                                            name="file"
                                            accept=".json,application/json"
                                            required
                                        />
                                        <InputError message={errors.file} />
                                    </div>
                                    <Button
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <Upload /> Import
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

EditCareerProfile.layout = {
    breadcrumbs: [
        { title: 'Career profile', href: CareerProfileController.edit() },
    ],
};
