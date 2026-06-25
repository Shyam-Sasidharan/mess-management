<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->enum('compensation_type', ['compensation', 'non_compensation'])->default('compensation')->after('type')->index();
        });

        DB::table('holidays')
            ->where('type', 'weekly_holiday')
            ->update(['compensation_type' => 'non_compensation']);

        DB::table('holidays')
            ->where('is_default_sunday', true)
            ->update(['compensation_type' => 'non_compensation']);
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('compensation_type');
        });
    }
};
