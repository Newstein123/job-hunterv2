<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COUNTRIES = [
        ['code' => 'HK', 'name' => 'Hong Kong', 'timezone' => 'Asia/Hong_Kong', 'aliases' => ['HKG', 'HKSAR', 'Kowloon'], 'sort_order' => 5],
        ['code' => 'MY', 'name' => 'Malaysia', 'timezone' => 'Asia/Kuala_Lumpur', 'aliases' => ['MYS', 'Kuala Lumpur', 'KL', 'Penang', 'Johor Bahru', 'Selangor'], 'sort_order' => 6],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::COUNTRIES as $c) {
            DB::table('countries')->updateOrInsert(['code' => $c['code']], [
                ...$c, 'aliases' => json_encode($c['aliases']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('countries')->whereIn('code', ['HK', 'MY'])->delete();
    }
};
