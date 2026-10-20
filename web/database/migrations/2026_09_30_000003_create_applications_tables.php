<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // One application per job: duplicate application protection.
            $table->foreignId('job_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('resume_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->index();
            $table->string('generation_state'); // pending | running | failed | done
            $table->text('generation_error')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->longText('cover_letter')->nullable();
            $table->json('validation')->nullable();
            $table->timestamp('earliest_send_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('application_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->json('match');
            $table->json('resume_selection');
            $table->json('skill_strategy');
            $table->json('application_strategy');
            $table->json('meta');
            $table->timestamps();
        });

        Schema::create('application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // User-level rule overrides, merged over the AI service's default/country profiles.
        Schema::create('application_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('rules');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_rules');
        Schema::dropIfExists('application_status_history');
        Schema::dropIfExists('application_strategies');
        Schema::dropIfExists('applications');
    }
};
