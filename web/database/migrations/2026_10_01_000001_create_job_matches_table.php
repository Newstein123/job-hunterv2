<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('analysis_state')->nullable()->after('source'); // pending | running | failed | done
            $table->text('analysis_error')->nullable()->after('analysis_state');
        });

        // How well a job fits the user's profile, from the deterministic matching stage.
        Schema::create('job_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('recommended_resume_id')->nullable()->constrained('resumes')->nullOnDelete();
            $table->string('fit')->index(); // strong | partial | weak | unknown
            $table->unsignedSmallInteger('required_covered');
            $table->unsignedSmallInteger('required_total');
            $table->unsignedSmallInteger('preferred_covered');
            $table->unsignedSmallInteger('preferred_total');
            $table->json('matching_skills');
            $table->json('missing_skills');
            $table->text('reason')->nullable();
            $table->json('match');
            $table->timestamp('profile_snapshot_at')->nullable(); // profile/resume version it was computed against
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_matches');
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['analysis_state', 'analysis_error']);
        });
    }
};
