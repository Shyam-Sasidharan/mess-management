<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\Role;
use App\Models\Subscription;
use App\Notifications\SubscriptionExpiryNotification;
use App\Notifications\BusinessAlertNotification;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\DeliveryService;
use App\Services\SubscriptionService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (SubscriptionService $service) { $service->expireDue(); })->name('expire-subscriptions')->dailyAt('00:05')->withoutOverlapping();
Schedule::call(function (DeliveryService $service) { $service->generateForDate(today()); })->name('generate-daily-deliveries')->dailyAt('00:10')->withoutOverlapping();
Schedule::call(function () {
    $admins = Role::where('slug', 'admin')->first()?->users()->where('is_active', true)->get() ?? collect();
    Subscription::with('customer')->where('status', 'active')->whereIn('end_date', [today()->toDateString(), today()->addDays(3)->toDateString(), today()->addDays(7)->toDateString()])->each(fn ($subscription) => $admins->each->notify(new SubscriptionExpiryNotification($subscription)));
})->name('subscription-expiry-notifications')->dailyAt('08:00')->withoutOverlapping();

Schedule::call(function () {
    $admins = Role::where('slug', 'admin')->first()?->users()->where('is_active', true)->get() ?? collect();
    $subscriptions = Subscription::whereIn('payment_status', ['pending', 'partial']);
    $count = $subscriptions->count();
    if ($count) {
        $total = $subscriptions->get()->sum('outstanding_amount');
        $admins->each->notify(new BusinessAlertNotification('Pending payments', "{$count} subscriptions have an outstanding total of ".Setting::value('currency', '₹').number_format($total, 2).'.', route('payments.index')));
    }
})->name('pending-payment-notifications')->dailyAt('08:05')->withoutOverlapping();

Schedule::call(function () {
    $total = (float) Expense::whereDate('expense_date', today())->sum('amount');
    if ($total >= (float) Setting::value('high_expense_threshold', 10000)) {
        $admins = Role::where('slug', 'admin')->first()?->users()->where('is_active', true)->get() ?? collect();
        $admins->each->notify(new BusinessAlertNotification('High expense alert', "Today's expenses reached ".Setting::value('currency', '₹').number_format($total, 2).'.', route('expenses.index', ['from' => today()->toDateString(), 'to' => today()->toDateString()])));
    }
})->name('high-expense-notifications')->dailyAt('20:00')->withoutOverlapping();

Schedule::call(function () {
    $month = now()->subMonthNoOverflow();
    $revenue = (float) Payment::whereYear('payment_date', $month->year)->whereMonth('payment_date', $month->month)->sum('amount');
    $expense = (float) Expense::whereYear('expense_date', $month->year)->whereMonth('expense_date', $month->month)->sum('amount');
    $currency = Setting::value('currency', '₹');
    $message = $month->format('F Y').": Revenue {$currency}".number_format($revenue, 2).", expense {$currency}".number_format($expense, 2).", profit {$currency}".number_format($revenue - $expense, 2).'.';
    $admins = Role::where('slug', 'admin')->first()?->users()->where('is_active', true)->get() ?? collect();
    $admins->each->notify(new BusinessAlertNotification('Monthly business summary', $message, route('reports.index', ['type' => 'profit', 'from' => $month->copy()->startOfMonth()->toDateString(), 'to' => $month->copy()->endOfMonth()->toDateString()])));
})->name('monthly-summary-notifications')->monthlyOn(1, '08:15')->withoutOverlapping();
