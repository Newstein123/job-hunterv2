<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per AI call, with the tokens the provider reported and the cost at our configured prices.
        Schema::create('llm_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task'); // job_analysis | email | reply_analysis | reply_draft
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('thinking_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });

        // Fingerprint of the description an analysis was made from, so it is only reused while unchanged.
        Schema::table('job_analyses', function (Blueprint $table) {
            $table->char('description_hash', 40)->nullable()->after('prompt_version');
        });
    }

    public function down(): void
    {
        Schema::table('job_analyses', fn (Blueprint $t) => $t->dropColumn('description_hash'));
        Schema::dropIfExists('llm_usages');
    }
};
