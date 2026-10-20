import { Form, Head } from '@inertiajs/react';
import ApplicationRuleController from '@/actions/App/Http/Controllers/ApplicationRuleController';
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
import { Textarea } from '@/components/ui/textarea';
import { asText } from '@/lib/format';

type RuleSections =
    | 'email_rules'
    | 'application_rules'
    | 'sending_rules'
    | 'cv_rules';
type Rules = Partial<Record<RuleSections, Record<string, unknown>>>;

type Props = {
    rules: Rules;
    defaults: Record<RuleSections, Record<string, unknown>>;
    weekdays: string[];
};

export default function EditRules({ rules, defaults, weekdays }: Props) {
    const value = (section: RuleSections, key: string) => rules[section]?.[key];
    const text = (section: RuleSections, key: string) =>
        asText(value(section, key));
    const placeholder = (section: RuleSections, key: string) => {
        const v = asText(defaults[section]?.[key]);

        return v ? `Default: ${v}` : '';
    };
    const triState = (section: RuleSections, key: string) => {
        const v = value(section, key);

        return v === true ? '1' : v === false ? '0' : '';
    };
    const listValue = (section: RuleSections, key: string, sep: string) => {
        const v = value(section, key);

        return Array.isArray(v) ? v.join(sep) : '';
    };

    return (
        <>
            <Head title="Rules" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title="Application rules"
                    description="Constraints the AI pipeline follows. Leave a field blank to use the default (or the job country's profile)."
                />

                <Form
                    {...ApplicationRuleController.update.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-6"
                >
                    {({ processing, errors }) => {
                        const err = (name: string) =>
                            (errors as Record<string, string | undefined>)[
                                name
                            ];
                        const input = (
                            section: RuleSections,
                            key: string,
                            label: string,
                            type = 'text',
                            hint?: string,
                        ) => (
                            <Field
                                label={label}
                                htmlFor={`${section}.${key}`}
                                error={err(`${section}.${key}`)}
                                hint={hint}
                            >
                                <Input
                                    id={`${section}.${key}`}
                                    name={`${section}[${key}]`}
                                    type={type}
                                    defaultValue={text(section, key)}
                                    placeholder={placeholder(section, key)}
                                />
                            </Field>
                        );
                        const yesNo = (
                            section: RuleSections,
                            key: string,
                            label: string,
                        ) => (
                            <Field
                                label={label}
                                htmlFor={`${section}.${key}`}
                                error={err(`${section}.${key}`)}
                            >
                                <NativeSelect
                                    id={`${section}.${key}`}
                                    name={`${section}[${key}]`}
                                    defaultValue={triState(section, key)}
                                >
                                    <option value="">
                                        Default (
                                        {defaults[section]?.[key]
                                            ? 'yes'
                                            : 'no'}
                                        )
                                    </option>
                                    <option value="1">Yes</option>
                                    <option value="0">No</option>
                                </NativeSelect>
                            </Field>
                        );

                        return (
                            <>
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Email</CardTitle>
                                    </CardHeader>
                                    <CardContent className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            label="Letter style"
                                            htmlFor="letter_style"
                                            error={err(
                                                'email_rules.letter_style',
                                            )}
                                            hint="From the Career Coaching Center templates."
                                        >
                                            <NativeSelect
                                                id="letter_style"
                                                name="email_rules[letter_style]"
                                                defaultValue={text(
                                                    'email_rules',
                                                    'letter_style',
                                                )}
                                            >
                                                <option value="">
                                                    Default (Modern)
                                                </option>
                                                <option value="modern">
                                                    Modern: “Dear Hiring
                                                    Manager”, shorter
                                                </option>
                                                <option value="traditional">
                                                    Traditional: to the company,
                                                    says where you saw the job
                                                </option>
                                            </NativeSelect>
                                        </Field>
                                        {input(
                                            'email_rules',
                                            'subject_format',
                                            'Fixed subject (optional)',
                                            'text',
                                            'Empty = rotate course subject lines. Placeholders: {position}, {company}, {candidate_name}',
                                        )}
                                        {input(
                                            'email_rules',
                                            'max_words',
                                            'Max words',
                                            'number',
                                        )}
                                        {input(
                                            'email_rules',
                                            'paragraph_count',
                                            'Body paragraphs',
                                            'number',
                                        )}
                                        {input('email_rules', 'tone', 'Tone')}
                                        {input(
                                            'email_rules',
                                            'language',
                                            'Language',
                                        )}
                                        {yesNo(
                                            'email_rules',
                                            'include_linkedin',
                                            'Include LinkedIn in signature',
                                        )}
                                        {yesNo(
                                            'email_rules',
                                            'include_phone',
                                            'Include phone in signature',
                                        )}
                                        {yesNo(
                                            'email_rules',
                                            'include_salary',
                                            'Mention salary',
                                        )}
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>Application</CardTitle>
                                    </CardHeader>
                                    <CardContent className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            label="Cover letter"
                                            htmlFor="cover_letter"
                                            error={err(
                                                'application_rules.cover_letter',
                                            )}
                                        >
                                            <NativeSelect
                                                id="cover_letter"
                                                name="application_rules[cover_letter]"
                                                defaultValue={text(
                                                    'application_rules',
                                                    'cover_letter',
                                                )}
                                            >
                                                <option value="">
                                                    Default (when the posting
                                                    asks)
                                                </option>
                                                <option value="when_requested">
                                                    When the posting asks
                                                </option>
                                                <option value="always">
                                                    Always
                                                </option>
                                                <option value="never">
                                                    Never
                                                </option>
                                            </NativeSelect>
                                        </Field>
                                        {yesNo(
                                            'application_rules',
                                            'mention_projects',
                                            'Mention a relevant project',
                                        )}
                                        {input(
                                            'application_rules',
                                            'max_emphasized_skills',
                                            'Max emphasized skills',
                                            'number',
                                        )}
                                        {input(
                                            'application_rules',
                                            'skills_to_emphasize',
                                            'Skills to emphasize',
                                            'text',
                                            'Comma-separated. Only used where your profile has evidence.',
                                        )}
                                        <Field
                                            label="Call to action"
                                            htmlFor="cta"
                                            error={err('application_rules.cta')}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="cta"
                                                name="application_rules[cta]"
                                                defaultValue={text(
                                                    'application_rules',
                                                    'cta',
                                                )}
                                                placeholder={placeholder(
                                                    'application_rules',
                                                    'cta',
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            label="Claims to avoid"
                                            htmlFor="claims_to_avoid"
                                            hint="One phrase per line. Drafts containing these are flagged."
                                            error={err(
                                                'application_rules.claims_to_avoid',
                                            )}
                                            className="sm:col-span-2"
                                        >
                                            <Textarea
                                                id="claims_to_avoid"
                                                name="application_rules[claims_to_avoid]"
                                                rows={3}
                                                defaultValue={listValue(
                                                    'application_rules',
                                                    'claims_to_avoid',
                                                    '\n',
                                                )}
                                            />
                                        </Field>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>Sending window</CardTitle>
                                        <CardDescription>
                                            When emails are allowed to be sent.
                                            Leave the timezone empty to use each
                                            job's country time (the employer's
                                            local time).
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-4 sm:grid-cols-3">
                                        <div className="flex flex-wrap items-center gap-2 sm:col-span-3">
                                            <span className="text-sm text-muted-foreground">
                                                Career Coaching Center windows
                                                (employer's time):
                                            </span>
                                            {[
                                                ['Morning', '06:30', '10:30'],
                                                ['Midday', '11:30', '15:30'],
                                            ].map(([label, start, end]) => (
                                                <Button
                                                    key={label}
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => {
                                                        const set = (
                                                            id: string,
                                                            v: string,
                                                        ) => {
                                                            const el =
                                                                document.getElementById(
                                                                    id,
                                                                ) as HTMLInputElement | null;
                                                            if (el) {
                                                                el.value = v;
                                                            }
                                                        };
                                                        set(
                                                            'sending_rules.start_time',
                                                            start,
                                                        );
                                                        set(
                                                            'sending_rules.end_time',
                                                            end,
                                                        );
                                                        set(
                                                            'sending_rules.timezone',
                                                            '',
                                                        );
                                                    }}
                                                >
                                                    {label} {start}–{end}
                                                </Button>
                                            ))}
                                            <span className="basis-full text-xs text-muted-foreground">
                                                Course guidance for applications
                                                from job websites (morning) and
                                                other outreach (midday). Click
                                                one, then Save rules.
                                            </span>
                                        </div>
                                        {input(
                                            'sending_rules',
                                            'timezone',
                                            'Timezone',
                                            'text',
                                            'Empty = job country time',
                                        )}
                                        {input(
                                            'sending_rules',
                                            'start_time',
                                            'Start',
                                            'time',
                                        )}
                                        {input(
                                            'sending_rules',
                                            'end_time',
                                            'End',
                                            'time',
                                        )}
                                        <fieldset className="grid gap-2 sm:col-span-3">
                                            <legend className="mb-2 text-sm font-medium">
                                                Allowed days{' '}
                                                <span className="font-normal text-muted-foreground">
                                                    (none checked = default,
                                                    Mon–Fri)
                                                </span>
                                            </legend>
                                            <div className="flex flex-wrap gap-4">
                                                {weekdays.map((day) => (
                                                    <label
                                                        key={day}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            name="sending_rules[allowed_days][]"
                                                            value={day}
                                                            defaultChecked={listValue(
                                                                'sending_rules',
                                                                'allowed_days',
                                                                ',',
                                                            )
                                                                .split(',')
                                                                .includes(day)}
                                                        />
                                                        {day.slice(0, 3)}
                                                    </label>
                                                ))}
                                            </div>
                                        </fieldset>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>CV</CardTitle>
                                        <CardDescription>
                                            Influences which resume is
                                            recommended. Resumes are never
                                            rewritten.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-4 sm:grid-cols-2">
                                        <fieldset className="grid gap-2">
                                            <legend className="mb-2 text-sm font-medium">
                                                Preferred formats
                                            </legend>
                                            <div className="flex gap-4">
                                                {['pdf', 'docx'].map((f) => (
                                                    <label
                                                        key={f}
                                                        className="flex items-center gap-2 text-sm uppercase"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            name="cv_rules[preferred_formats][]"
                                                            value={f}
                                                            defaultChecked={listValue(
                                                                'cv_rules',
                                                                'preferred_formats',
                                                                ',',
                                                            )
                                                                .split(',')
                                                                .includes(f)}
                                                        />
                                                        {f}
                                                    </label>
                                                ))}
                                            </div>
                                        </fieldset>
                                        {input(
                                            'cv_rules',
                                            'max_pages',
                                            'Max pages',
                                            'number',
                                        )}
                                    </CardContent>
                                </Card>

                                <div>
                                    <Button disabled={processing}>
                                        Save rules
                                    </Button>
                                </div>
                            </>
                        );
                    }}
                </Form>
            </div>
        </>
    );
}

EditRules.layout = {
    breadcrumbs: [{ title: 'Rules', href: ApplicationRuleController.edit() }],
};
