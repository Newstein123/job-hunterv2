<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_profiles', function (Blueprint $table) {
            $table->string('availability', 100)->nullable()->after('location');
        });

        // The markets the user is applying to (a subset of the supported countries).
        Schema::create('career_profile_country', function (Blueprint $table) {
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->primary(['career_profile_id', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_profile_country');
        Schema::table('career_profiles', fn (Blueprint $t) => $t->dropColumn('availability'));
    }
};
