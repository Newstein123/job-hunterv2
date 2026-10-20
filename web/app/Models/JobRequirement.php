<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $job_analysis_id
 * @property string $type
 * @property string $value
 */
#[WithoutTimestamps]
#[Fillable(['type', 'value'])]
class JobRequirement extends Model {}
