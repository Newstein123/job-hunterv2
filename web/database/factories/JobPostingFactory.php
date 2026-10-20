<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobPosting> */
class JobPostingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'position' => 'Backend Engineer',
            'company' => fake()->company(),
            'description' => str_repeat('We need a PHP and Laravel engineer to build REST APIs. ', 3),
            'url' => fake()->unique()->url(),
            'country_id' => fn () => Country::where('code', 'SG')->value('id'),
            'contact_email' => 'jobs@acme-careers.com',
            'source' => 'LinkedIn',
        ];
    }
}
