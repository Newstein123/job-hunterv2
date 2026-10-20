<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for sending and syncing: queued, sent, failed, retrying, cancelled,
 * reply_detected, bounce_detected, account_error.
 *
 * @property int $id
 * @property int|null $email_account_id
 * @property int|null $email_message_id
 * @property string $type
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['email_account_id', 'email_message_id', 'type', 'payload'])]
class EmailEvent extends Model
{
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
