<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ResumeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An existing resume uploaded by the user. JobHunter selects between these; it never rewrites them.
 *
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property string $original_filename
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property bool $is_active
 * @property string|null $review_state pending | running | done | failed
 * @property array<string, mixed>|null $review
 * @property string|null $review_error
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['label', 'original_filename', 'path', 'mime_type', 'size', 'is_active'])]
#[Hidden(['path'])]
class Resume extends Model
{
    /** @use HasFactory<ResumeFactory> */
    use HasFactory;

    /** Where resume files live: local in development, an object storage bucket in production (RESUME_DISK). */
    public static function disk(): string
    {
        return (string) config('filesystems.resume_disk', 'local');
    }

    public const EXTENSIONS = ['pdf', 'docx', 'txt', 'md'];

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_RUNNING = 'running';

    public const REVIEW_DONE = 'done';

    public const REVIEW_FAILED = 'failed';

    /**
     * Save review fields without touching updated_at: a CV check doesn't change the resume,
     * so job fits computed from it must not be marked out of date.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveReview(array $attributes): void
    {
        $this->timestamps = false;
        $this->forceFill($attributes)->save();
        $this->timestamps = true;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'size' => 'integer', 'review' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_filename, PATHINFO_EXTENSION));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
