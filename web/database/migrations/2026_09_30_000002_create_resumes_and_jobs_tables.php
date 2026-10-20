<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('original_filename');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedInteger('size');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('position')->nullable();
            $table->string('company')->nullable();
            $table->longText('description');
            $table->string('url', 2048)->nullable();
            $table->string('location')->nullable();
            $table->string('country')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('job_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->json('warnings')->nullable();
            $table->string('model');
            $table->string('prompt_version');
            $table->timestamps();
        });

        Schema::create('job_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_analysis_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // required_skill | preferred_skill | requirement | responsibility | keyword
            $table->string('value', 500);
            $table->index(['job_analysis_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_requirements');
        Schema::dropIfExists('job_analyses');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('resumes');
    }
};
