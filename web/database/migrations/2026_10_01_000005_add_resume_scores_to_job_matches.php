<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_matches', function (Blueprint $table) {
            // Every resume scored against this job, with skills it could add (CV analysis).
            $table->json('resume_scores')->nullable()->after('match');
        });
    }

    public function down(): void
    {
        Schema::table('job_matches', fn (Blueprint $t) => $t->dropColumn('resume_scores'));
    }
};
