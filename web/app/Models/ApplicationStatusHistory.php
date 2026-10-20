<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $application_id
 * @property int|null $user_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 */
#[Table('application_status_history')]
#[Fillable(['user_id', 'from_status', 'to_status', 'note'])]
class ApplicationStatusHistory extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
