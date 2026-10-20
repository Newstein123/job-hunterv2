<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            // CV check: course checklist + AI suggestions. Suggestions only; the file is never changed.
            $table->string('review_state', 20)->nullable()->after('is_active');
            $table->json('review')->nullable()->after('review_state');
            $table->string('review_error', 500)->nullable()->after('review');
            $table->timestamp('reviewed_at')->nullable()->after('review_error');
        });
    }

    public function down(): void
    {
        Schema::table('resumes', fn (Blueprint $t) => $t->dropColumn(['review_state', 'review', 'review_error', 'reviewed_at']));
    }
};
