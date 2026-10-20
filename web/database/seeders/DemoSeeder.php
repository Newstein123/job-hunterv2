<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Local demo data from ai-service/examples: a fictional candidate, three resumes and two jobs.
 *
 *     php artisan db:seed --class=DemoSeeder   (login: demo@example.com / password)
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $examples = base_path('../ai-service/examples');
        $profile = json_decode((string) file_get_contents("{$examples}/candidate_profile.json"), true);

        $user = User::firstOrCreate(['email' => 'demo@example.com'], [
            'name' => $profile['name'], 'password' => 'password', 'email_verified_at' => now(),
        ]);
        $user->jobPostings()->delete();
        $user->resumes()->each(fn (Resume $r) => Storage::disk(Resume::DISK)->delete($r->path) && $r->delete());
        $user->careerProfile()->delete();

        $career = $user->careerProfile()->create([
            'full_name' => $profile['name'], 'email' => $profile['email'], 'phone' => $profile['phone'],
            'linkedin' => $profile['linkedin'], 'location' => $profile['location'],
            'headline' => $profile['headline'], 'summary' => $profile['summary'], 'languages' => $profile['languages'],
        ]);
        $career->skills()->createMany($profile['skills']);
        $dates = fn (array $e) => ['start_date' => $e['start'], 'end_date' => $e['end']];
        $without = fn (array $e, array $keys) => array_diff_key($e, array_flip($keys));
        foreach ($profile['experiences'] as $e) {
            $career->experiences()->create([...$without($e, ['id', 'start', 'end']), ...$dates($e)]);
        }
        foreach ($profile['projects'] as $p) {
            $career->projects()->create($without($p, ['id']));
        }
        foreach ($profile['education'] as $e) {
            $career->education()->create([...$without($e, ['id', 'start', 'end']), ...$dates($e)]);
        }

        $labels = ['backend_developer.pdf' => 'Backend Developer CV', 'fullstack_developer.docx' => 'Full Stack CV',
            'cloud_engineer.md' => 'Cloud / AWS CV'];
        foreach ($labels as $file => $label) {
            $path = "resumes/{$user->id}/{$file}";
            Storage::disk(Resume::DISK)->put($path, (string) file_get_contents("{$examples}/resumes/{$file}"));
            $user->resumes()->create([
                'label' => $label, 'original_filename' => $file, 'path' => $path,
                'mime_type' => (string) mime_content_type("{$examples}/resumes/{$file}"),
                'size' => (int) filesize("{$examples}/resumes/{$file}"),
            ]);
        }

        $meta = json_decode((string) file_get_contents("{$examples}/jobs/backend_laravel.meta.json"), true);
        JobPosting::unguarded(fn () => $user->jobPostings()->createMany([
            [...$meta, 'country_id' => Country::resolve('Singapore')?->id,
                'description' => file_get_contents("{$examples}/jobs/backend_laravel.txt")],
            ['description' => file_get_contents("{$examples}/jobs/fullstack_vue.txt"), 'country_id' => Country::resolve('Singapore')?->id,
                'source' => 'Company website'],
        ]));
    }
}
