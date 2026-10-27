<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // AI drafts started (applications + regenerations), for the per-user draft limit.
            // A counter, so deleting jobs or applications doesn't free up the limit.
            $table->unsignedInteger('ai_drafts_used')->default(0);
        });

        // Start existing users at the drafts they already have.
        DB::table('users')->update(['ai_drafts_used' => DB::raw(
            '(select count(*) from application_strategies join applications on applications.id = application_strategies.application_id where applications.user_id = users.id)'
        )]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ai_drafts_used');
        });
    }
};
