<?php

namespace Database\Factories;

use App\Models\CareerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CareerProfile> */
class CareerProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'full_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'headline' => 'Backend developer',
            'languages' => ['English'],
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (CareerProfile $profile) {
            $profile->skills()->createMany([['name' => 'PHP', 'years' => 5], ['name' => 'Laravel', 'years' => 4]]);
            $profile->experiences()->create([
                'title' => 'PHP Developer', 'company' => 'Acme', 'start_date' => '2020-01-01',
                'summary' => 'Built Laravel APIs', 'achievements' => ['Shipped REST APIs'], 'skills' => ['PHP', 'Laravel'],
            ]);
        });
    }
}
