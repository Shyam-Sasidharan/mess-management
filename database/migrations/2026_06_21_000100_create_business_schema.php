<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('password');
            $table->string('mobile', 20)->nullable()->after('email');
            $table->index(['role_id', 'is_active']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code', 30)->unique();
            $table->string('name')->index();
            $table->enum('gender', ['male', 'female', 'other']);
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('place')->nullable()->index();
            $table->string('primary_mobile', 20)->index();
            $table->string('secondary_mobile', 20)->nullable();
            $table->text('primary_address');
            $table->text('secondary_address')->nullable();
            $table->string('landmark')->nullable();
            $table->text('google_map_url')->nullable();
            $table->text('notes')->nullable();
            $table->text('food_instructions')->nullable();
            $table->enum('status', ['active', 'expired', 'paused'])->default('active')->index();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('subscription_no', 30)->unique();
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->unsignedSmallInteger('subscription_days');
            $table->boolean('breakfast')->default(false);
            $table->boolean('lunch')->default(false);
            $table->boolean('dinner')->default(false);
            $table->unsignedTinyInteger('meal_count');
            $table->enum('package_type', ['one_time', 'two_time', 'three_time']);
            $table->decimal('amount', 12, 2);
            $table->enum('payment_status', ['paid', 'partial', 'pending', 'overpaid'])->default('pending')->index();
            $table->enum('status', ['active', 'expired', 'paused', 'cancelled'])->default('active')->index();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_id', 'status']);
            $table->index(['end_date', 'status']);
        });

        Schema::create('subscription_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('new_subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->date('renewed_on')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('new_subscription_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 30)->unique();
            $table->uuid('transaction_token')->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->date('payment_date')->index();
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['cash', 'bank', 'upi', 'card', 'other'])->default('cash');
            $table->enum('payment_type', ['full', 'partial'])->default('partial');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_id', 'payment_date']);
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('delivery_date')->index();
            $table->enum('meal_type', ['breakfast', 'lunch', 'dinner'])->index();
            $table->enum('status', ['pending', 'delivered', 'missed', 'skipped'])->default('pending')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['subscription_id', 'delivery_date', 'meal_type'], 'deliveries_subscription_day_meal_unique');
            $table->index(['delivery_date', 'meal_type', 'status']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 7)->default('#F4B400');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('expense_date')->index();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('vendor_name')->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('bill_path')->nullable();
            $table->string('bill_original_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['expense_category_id', 'expense_date']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->index();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->enum('type', ['string', 'integer', 'decimal', 'boolean', 'json'])->default('string');
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscription_renewals');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('customers');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn(['role_id', 'is_active', 'mobile']);
        });
        Schema::dropIfExists('roles');
    }
};
