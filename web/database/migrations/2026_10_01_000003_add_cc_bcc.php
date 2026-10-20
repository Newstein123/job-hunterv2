<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CC addresses the posting asks for; copied to the application when it is started.
        Schema::table('jobs', function (Blueprint $table) {
            $table->json('cc_emails')->nullable()->after('contact_email');
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->json('cc')->nullable()->after('cover_letter');
            $table->json('bcc')->nullable()->after('cc');
        });
        // What was actually sent, for the record.
        Schema::table('email_messages', function (Blueprint $table) {
            $table->json('cc')->nullable()->after('to_email');
            $table->json('bcc')->nullable()->after('cc');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', fn (Blueprint $t) => $t->dropColumn(['cc', 'bcc']));
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn(['cc', 'bcc']));
        Schema::table('jobs', fn (Blueprint $t) => $t->dropColumn('cc_emails'));
    }
};
