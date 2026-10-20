<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // posting = a job the user found; speculative = a company from the user's list, no posting.
            $table->string('kind', 20)->default('posting')->after('user_id')->index();
            $table->string('website', 2048)->nullable()->after('url');
            $table->string('industry', 500)->nullable()->after('website');
            $table->text('specialties')->nullable()->after('industry');
            // What kind of inbox contact_email looks like (hiring, general, other_team, person).
            $table->string('contact_flag', 20)->nullable()->after('contact_email');
            // The resume the user chose to send (speculative); null = JobHunter picks the best fit.
            $table->foreignId('preferred_resume_id')->nullable()->after('contact_flag')->constrained('resumes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_resume_id');
            $table->dropColumn(['kind', 'website', 'industry', 'specialties', 'contact_flag']);
        });
    }
};
