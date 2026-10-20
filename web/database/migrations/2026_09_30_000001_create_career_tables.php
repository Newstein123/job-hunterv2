<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('location')->nullable();
            $table->string('headline')->nullable();
            $table->text('summary')->nullable();
            $table->json('languages')->nullable();
            $table->timestamps();
        });

        Schema::create('career_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('company');
            $table->string('location')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->text('summary')->nullable();
            $table->json('achievements')->nullable();
            $table->json('skills')->nullable();
            $table->timestamps();
        });

        Schema::create('career_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('level')->nullable();
            $table->decimal('years', 4, 1)->nullable();
            $table->timestamps();
            $table->unique(['career_profile_id', 'name']);
        });

        Schema::create('career_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('skills')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
        });

        Schema::create('career_education', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->string('institution');
            $table->string('degree');
            $table->string('field')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_education');
        Schema::dropIfExists('career_projects');
        Schema::dropIfExists('career_skills');
        Schema::dropIfExists('career_experiences');
        Schema::dropIfExists('career_profiles');
    }
};
