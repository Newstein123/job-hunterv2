<?php

namespace Database\Factories;

use App\Models\Resume;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/** @extends Factory<Resume> */
class ResumeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Backend CV',
            'original_filename' => 'backend.md',
            'path' => 'resumes/test/'.fake()->uuid().'.md',
            'mime_type' => 'text/markdown',
            'size' => 100,
            'is_active' => true,
        ];
    }

    /** Also write the file to the (faked) resume disk. */
    public function withFile(string $content = "PHP Laravel developer\n"): static
    {
        return $this->afterCreating(fn (Resume $r) => Storage::disk(Resume::disk())->put($r->path, $content));
    }
}
