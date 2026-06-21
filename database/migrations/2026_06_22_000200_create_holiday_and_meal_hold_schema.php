<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->date('original_end_date')->nullable()->after('end_date')->index();
            $table->unsignedSmallInteger('holiday_compensation_days')->default(0)->after('subscription_days');
            $table->unsignedSmallInteger('meal_hold_compensation_days')->default(0)->after('holiday_compensation_days');
        });
        DB::table('subscriptions')->update(['original_end_date' => DB::raw('end_date')]);

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_date')->unique();
            $table->string('title');
            $table->text('reason')->nullable();
            $table->enum('type', ['weekly_holiday', 'festival', 'emergency', 'custom'])->default('custom');
            $table->boolean('is_default_sunday')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['holiday_date', 'status']);
        });

        Schema::create('customer_meal_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('hold_date')->index();
            $table->boolean('breakfast_required')->default(false);
            $table->boolean('lunch_required')->default(false);
            $table->boolean('dinner_required')->default(false);
            $table->boolean('is_full_day_hold')->default(false)->index();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['subscription_id', 'hold_date']);
            $table->index(['customer_id', 'hold_date']);
        });

        Schema::create('subscription_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('compensation_date')->index();
            $table->enum('compensation_type', ['holiday', 'meal_hold'])->index();
            $table->string('reason')->nullable();
            $table->unsignedTinyInteger('days_added')->default(1);
            $table->timestamps();
            $table->unique(['subscription_id', 'compensation_date'], 'subscription_compensation_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_compensations');
        Schema::dropIfExists('customer_meal_holds');
        Schema::dropIfExists('holidays');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['original_end_date', 'holiday_compensation_days', 'meal_hold_compensation_days']);
        });
    }
};
