<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Application> */
class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'job_id' => JobPosting::factory(),
            'user_id' => fn (array $attrs) => JobPosting::query()->whereKey($attrs['job_id'])->value('user_id'),
            'status' => ApplicationStatus::Generated,
            'generation_state' => Application::GENERATION_DONE,
            'subject' => 'Backend Engineer - Application - Alex',
            'body' => "Dear Hiring Team,\n\nHello.\n\nKind regards,\nAlex",
            'validation' => ['errors' => [], 'warnings' => [], 'attempts' => 1],
        ];
    }

    public function status(ApplicationStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
