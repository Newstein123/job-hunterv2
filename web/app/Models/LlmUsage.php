<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $job_id
 * @property int|null $application_id
 * @property string $task
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $thinking_tokens
 * @property string $cost_usd
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['user_id', 'job_id', 'application_id', 'task', 'model', 'input_tokens', 'output_tokens', 'thinking_tokens', 'cost_usd'])]
class LlmUsage extends Model
{
    public const UPDATED_AT = null;
}
