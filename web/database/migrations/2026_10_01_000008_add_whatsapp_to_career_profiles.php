<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_profiles', function (Blueprint $table) {
            $table->string('whatsapp', 50)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('career_profiles', fn (Blueprint $t) => $t->dropColumn('whatsapp'));
    }
};
