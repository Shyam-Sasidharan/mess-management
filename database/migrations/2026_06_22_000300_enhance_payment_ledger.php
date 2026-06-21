<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'payment_type')) Schema::table('payments', function (Blueprint $table) { $table->enum('payment_type', ['full', 'partial'])->default('partial')->after('method'); });
        if (! Schema::hasColumn('payments', 'transaction_token')) Schema::table('payments', function (Blueprint $table) { $table->uuid('transaction_token')->nullable()->unique()->after('receipt_no'); });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE subscriptions MODIFY payment_status ENUM('paid','partial','pending','overpaid') NOT NULL DEFAULT 'pending'");
        }
        DB::table('subscriptions')->select(['id', 'amount'])->orderBy('id')->each(function ($subscription) {
            $runningTotal = 0.0;
            foreach (DB::table('payments')->where('subscription_id', $subscription->id)->whereNull('deleted_at')->orderBy('payment_date')->orderBy('id')->get(['id', 'amount']) as $payment) {
                $runningTotal += (float) $payment->amount;
                DB::table('payments')->where('id', $payment->id)->update(['payment_type' => $runningTotal >= (float) $subscription->amount ? 'full' : 'partial']);
            }
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::table('subscriptions')->where('payment_status', 'overpaid')->update(['payment_status' => 'paid']);
            DB::statement("ALTER TABLE subscriptions MODIFY payment_status ENUM('paid','partial','pending') NOT NULL DEFAULT 'pending'");
        }
        Schema::table('payments', function (Blueprint $table) { $table->dropColumn(['payment_type', 'transaction_token']); });
    }
};
