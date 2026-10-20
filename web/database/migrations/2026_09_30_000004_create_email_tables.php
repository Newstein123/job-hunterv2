<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // gmail (outlook later)
            $table->string('email');
            $table->string('auth_type'); // oauth
            $table->text('access_token')->nullable(); // encrypted
            $table->text('refresh_token')->nullable(); // encrypted
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status'); // active | needs_reauth | restricted | disabled
            $table->string('status_reason')->nullable();
            $table->unsignedSmallInteger('daily_limit');
            $table->unsignedSmallInteger('sent_today')->default(0);
            $table->date('usage_date')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider', 'email']);
        });

        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction'); // outbound | inbound
            $table->string('status')->index(); // queued | scheduled | sending | sent | failed | retrying | cancelled | received
            $table->string('to_email')->nullable();
            $table->string('from_email')->nullable();
            $table->string('subject')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('thread_id')->nullable()->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['email_account_id', 'provider_message_id']);
        });

        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type'); // queued | sent | failed | retrying | cancelled | reply_detected | bounce_detected | account_error
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('email_account_id')->nullable()->after('resume_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_account_id');
        });
        Schema::dropIfExists('email_events');
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('email_accounts');
    }
};
