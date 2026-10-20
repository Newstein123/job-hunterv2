<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            // Inbound: the employer's reply text and what the AI made of it.
            $table->longText('body_text')->nullable()->after('subject');
            $table->text('snippet')->nullable()->after('body_text');
            $table->string('rfc_message_id', 512)->nullable()->after('thread_id'); // Message-ID header, for In-Reply-To
            $table->boolean('is_auto_reply')->default(false)->after('rfc_message_id');
            $table->json('analysis')->nullable()->after('is_auto_reply');
            $table->string('analysis_state')->nullable()->after('analysis'); // pending | running | failed | done
            $table->text('analysis_error')->nullable()->after('analysis_state');
            // Outbound replies: which inbound message they answer, and validation of the AI draft.
            $table->foreignId('in_reply_to_id')->nullable()->after('application_id')->constrained('email_messages')->nullOnDelete();
            $table->boolean('attach_resume')->default(true)->after('respect_window');
            $table->json('validation')->nullable()->after('attach_resume');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('in_reply_to_id');
            $table->dropColumn(['body_text', 'snippet', 'rfc_message_id', 'is_auto_reply', 'analysis', 'analysis_state',
                'analysis_error', 'attach_resume', 'validation']);
        });
    }
};
