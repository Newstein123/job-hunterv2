import { Form, Head } from '@inertiajs/react';
import JobPostingController from '@/actions/App/Http/Controllers/JobPostingController';
import Heading from '@/components/heading';
import JobFormFields from '@/components/jobs/job-form-fields';
import { Button } from '@/components/ui/button';
import type { CountryOption } from '@/types';

export default function CreateJob({
    sources,
    countries,
}: {
    sources: string[];
    countries: CountryOption[];
}) {
    return (
        <>
            <Head title="Add job" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title="Add a job"
                    description="A job you found on LinkedIn, a company site, a referral, anywhere."
                />
                <Form
                    {...JobPostingController.store.form()}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <JobFormFields
                                sources={sources}
                                countries={countries}
                                errors={errors}
                            />
                            <div>
                                <Button disabled={processing}>Add job</Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreateJob.layout = {
    breadcrumbs: [
        { title: 'Jobs', href: JobPostingController.index() },
        { title: 'Add job', href: JobPostingController.create() },
    ],
};
