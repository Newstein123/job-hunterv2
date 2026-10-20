<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sending window is gone: emails go out when the user schedules them, or straight away.
 * Recommended send times are shown as information only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropColumn('respect_window');
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('earliest_send_at');
        });

        // The AI service rejects unknown rule sections, so drop saved sending rules.
        DB::table('application_rules')->orderBy('id')->each(function (object $row) {
            $rules = json_decode((string) $row->rules, true);
            if (is_array($rules) && array_key_exists('sending_rules', $rules)) {
                unset($rules['sending_rules']);
                DB::table('application_rules')->where('id', $row->id)->update(['rules' => json_encode((object) $rules)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->boolean('respect_window')->default(true)->after('scheduled_at');
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('earliest_send_at')->nullable();
        });
    }
};
