<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Target markets at launch. Add more rows later; set is_active to show them. */
    private const COUNTRIES = [
        ['code' => 'AE', 'name' => 'United Arab Emirates', 'timezone' => 'Asia/Dubai', 'aliases' => ['UAE', 'Dubai', 'Abu Dhabi', 'Sharjah', 'Emirates'], 'sort_order' => 1],
        ['code' => 'AU', 'name' => 'Australia', 'timezone' => 'Australia/Sydney', 'aliases' => ['AUS', 'Sydney', 'Melbourne', 'Brisbane', 'Perth'], 'sort_order' => 2],
        ['code' => 'SG', 'name' => 'Singapore', 'timezone' => 'Asia/Singapore', 'aliases' => ['SGP'], 'sort_order' => 3],
        ['code' => 'TH', 'name' => 'Thailand', 'timezone' => 'Asia/Bangkok', 'aliases' => ['THA', 'Bangkok'], 'sort_order' => 4],
    ];

    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique(); // ISO 3166-1 alpha-2
            $table->string('name')->unique();   // also the AI service's rules profile name
            $table->string('timezone');          // default sending timezone
            $table->json('aliases')->nullable(); // extra names accepted in imports
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('countries')->insert(array_map(fn (array $c) => [
            ...$c, 'aliases' => json_encode($c['aliases']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], self::COUNTRIES));

        Schema::table('jobs', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('location')->constrained()->nullOnDelete();
        });

        // Carry over free-text countries that match a known country; others become empty.
        foreach (DB::table('countries')->get() as $country) {
            $names = [strtolower($country->name), strtolower($country->code), ...array_map('strtolower', json_decode($country->aliases, true))];
            DB::table('jobs')->whereIn(DB::raw('LOWER(country)'), $names)->update(['country_id' => $country->id]);
        }

        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('country')->nullable()->after('location');
        });
        foreach (DB::table('countries')->get() as $country) {
            DB::table('jobs')->where('country_id', $country->id)->update(['country' => $country->name]);
        }
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
        });
        Schema::dropIfExists('countries');
    }
};
