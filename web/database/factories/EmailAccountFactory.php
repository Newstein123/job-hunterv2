<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailAccount> */
class EmailAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => EmailAccount::PROVIDER_GMAIL,
            'email' => fake()->unique()->userName().'@gmail.com',
            'auth_type' => 'oauth',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/gmail.send', 'https://www.googleapis.com/auth/gmail.metadata'],
            'status' => EmailAccount::STATUS_ACTIVE,
            'daily_limit' => 20,
        ];
    }
}
