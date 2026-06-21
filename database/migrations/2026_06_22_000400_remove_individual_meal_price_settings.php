<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->whereIn('key', ['breakfast_price', 'lunch_price', 'dinner_price'])->delete();
    }

    public function down(): void
    {
        foreach (['breakfast_price', 'lunch_price', 'dinner_price'] as $key) {
            DB::table('settings')->updateOrInsert(['key' => $key], [
                'group' => 'pricing', 'value' => 0, 'type' => 'decimal', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
};
