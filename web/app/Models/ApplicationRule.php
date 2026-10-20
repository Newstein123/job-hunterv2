<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's rule overrides. Same shape as the AI service's rules (cv/email/application/sending);
 * merged over its default and country profiles.
 *
 * @property int $id
 * @property int $user_id
 * @property array<string, array<string, mixed>> $rules
 */
#[Fillable(['rules'])]
class ApplicationRule extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
