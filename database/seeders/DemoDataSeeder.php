<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionRenewal;
use App\Models\User;
use App\Models\CustomerMealHold;
use App\Notifications\BusinessAlertNotification;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoDataSeeder extends Seeder
{
    private array $names = [
        'Aarav Nair', 'Meera Menon', 'Rohan Das', 'Diya Thomas', 'Vivek Kumar', 'Anjali Pillai',
        'Rahul Krishnan', 'Sneha Babu', 'Arjun Mohan', 'Nisha Varma', 'Kiran Raj', 'Lakshmi S',
        'Adithya Paul', 'Fathima Noor', 'Manu Joseph', 'Reshma Ravi', 'Sanjay Dev', 'Neha Mathew',
    ];

    private array $places = ['Kakkanad', 'Edappally', 'Palarivattom', 'Vyttila', 'Kaloor', 'Thrikkakara'];

    public function run(): void
    {
        DB::transaction(function () {
            $admin = User::where('email', 'admin@goldenmess.test')->firstOrFail();
            $staffRole = Role::where('slug', 'staff')->firstOrFail();
            User::updateOrCreate(['email' => 'staff@goldenmess.test'], [
                'role_id' => $staffRole->id,
                'name' => 'Demo Delivery Staff',
                'mobile' => '9000000099',
                'password' => 'ChangeMe@123',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            Expense::where('notes', 'like', '[DEMO-DATA]%')->forceDelete();
            CustomerMealHold::where('reason', 'like', '[DEMO-DATA]%')->delete();
            Delivery::whereHas('subscription', fn ($query) => $query->where('subscription_no', 'like', 'DSUB-%')->orWhere('subscription_no', 'like', 'DREN-%'))->delete();
            DB::table('notifications')->where('data', 'like', '%DEMO-DATA%')->delete();

            $months = [now()->subMonthsNoOverflow(2)->startOfMonth(), now()->subMonthNoOverflow()->startOfMonth()];
            $customers = collect();

            foreach ($months as $monthIndex => $month) {
                foreach (range(1, 9) as $position) {
                    $index = ($monthIndex * 9) + $position - 1;
                    $customer = $this->seedCustomer($admin, $month, $position, $index);
                    $subscription = $this->seedSubscription($customer, $admin, $month, $position);
                    $this->seedPayment($subscription, $admin, $position, $month);
                    $this->seedDeliveries($subscription, $admin, $position);
                    $customers->push($customer);
                }
            }

            $this->seedMealHolds($admin);
            $dates = app(\App\Services\SubscriptionDateService::class);
            Subscription::where('subscription_no', 'like', 'DSUB-%')->each(fn ($subscription) => $dates->recalculate($subscription));
            $this->seedRenewals($customers->take(3), $admin);
            $this->seedExpenses($admin, $months);
            $this->seedNotifications($admin, $customers);
            Subscription::where('subscription_no', 'like', 'DREN-%')->each(fn ($subscription) => $dates->recalculate($subscription));
            $payments = app(\App\Services\PaymentService::class);
            Subscription::where(fn ($query) => $query->where('subscription_no', 'like', 'DSUB-%')->orWhere('subscription_no', 'like', 'DREN-%'))->each(fn ($subscription) => $payments->syncStatus($subscription));
        });
    }

    private function seedCustomer(User $admin, Carbon $month, int $position, int $index): Customer
    {
        $code = 'DEMO-'.$month->format('ym').'-'.str_pad((string) $position, 2, '0', STR_PAD_LEFT);
        $status = $month->isSameMonth(now()->subMonthsNoOverflow(2)) ? 'expired' : match ($position % 5) {
            0 => 'paused', 1, 2, 3 => 'active', default => 'expired',
        };
        $place = $this->places[$index % count($this->places)];
        $customer = Customer::withTrashed()->updateOrCreate(['customer_code' => $code], [
            'name' => $this->names[$index],
            'gender' => $index % 2 ? 'female' : 'male',
            'age' => 22 + ($index % 24),
            'place' => $place,
            'primary_mobile' => '90'.str_pad((string) (10000000 + $index), 8, '0', STR_PAD_LEFT),
            'secondary_mobile' => $position % 3 === 0 ? '91'.str_pad((string) (20000000 + $index), 8, '0', STR_PAD_LEFT) : null,
            'primary_address' => (10 + $position).', Demo Residency, '.$place.', Kochi',
            'secondary_address' => null,
            'landmark' => ['Metro Station', 'Community Hall', 'City Mall'][$position % 3],
            'google_map_url' => 'https://maps.google.com/?q='.urlencode($place.', Kochi'),
            'notes' => '[DEMO-DATA] Customer created for flow testing.',
            'food_instructions' => $position % 4 === 0 ? 'Less spicy; no peanuts.' : ($position % 3 === 0 ? 'Please avoid excess oil.' : null),
            'status' => $status,
            'paused_at' => $status === 'paused' ? now()->subDays(4) : null,
            'created_by' => $admin->id,
            'deleted_at' => null,
        ]);
        $customer->forceFill(['created_at' => $month->copy()->addDays($position + 1)->setTime(10, 0)])->saveQuietly();
        return $customer;
    }

    private function seedSubscription(Customer $customer, User $admin, Carbon $month, int $position): Subscription
    {
        $mealCount = (($position - 1) % 3) + 1;
        $meals = match ($mealCount) {
            1 => ['breakfast' => true, 'lunch' => false, 'dinner' => false],
            2 => ['breakfast' => false, 'lunch' => true, 'dinner' => true],
            default => ['breakfast' => true, 'lunch' => true, 'dinner' => true],
        };
        $days = $month->isSameMonth(now()->subMonthNoOverflow()) && $customer->status !== 'expired' ? 60 : 30;
        $start = $month->copy()->addDays(min($position, 12));
        $status = $customer->status === 'paused' ? 'paused' : ($customer->status === 'active' ? 'active' : 'expired');
        $amount = [1 => 1700, 2 => 2700, 3 => 3700][$mealCount];

        return Subscription::withTrashed()->updateOrCreate(['subscription_no' => 'DSUB-'.$month->format('ym').'-'.str_pad((string) $position, 2, '0', STR_PAD_LEFT)], [
            'customer_id' => $customer->id,
            'start_date' => $start,
            'end_date' => $start->copy()->addDays($days - 1),
            'subscription_days' => $days,
            ...$meals,
            'meal_count' => $mealCount,
            'package_type' => [1 => 'one_time', 2 => 'two_time', 3 => 'three_time'][$mealCount],
            'amount' => $amount,
            'payment_status' => match ($position % 3) { 0 => 'pending', 1 => 'paid', default => 'partial' },
            'status' => $status,
            'paused_at' => $status === 'paused' ? now()->subDays(4) : null,
            'created_by' => $admin->id,
            'deleted_at' => null,
        ]);
    }

    private function seedPayment(Subscription $subscription, User $admin, int $position, Carbon $month): void
    {
        $receipt = (str_starts_with($subscription->subscription_no, 'DREN-') ? 'DRPAY-' : 'DPAY-').$month->format('ym').'-'.str_pad((string) $position, 2, '0', STR_PAD_LEFT);
        if ($subscription->payment_status === 'pending') {
            Payment::where('receipt_no', $receipt)->forceDelete();
            return;
        }
        $amount = $subscription->payment_status === 'paid' ? (float) $subscription->amount : [1 => 900, 2 => 1440, 3 => 1800][$subscription->meal_count];
        Payment::withTrashed()->updateOrCreate(['receipt_no' => $receipt], [
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'payment_date' => $month->copy()->addDays(min(5 + $position, 25)),
            'amount' => $amount,
            'method' => ['cash', 'upi', 'bank', 'card'][$position % 4],
            'notes' => '[DEMO-DATA] Sample subscription receipt.',
            'created_by' => $admin->id,
            'deleted_at' => null,
        ]);
    }

    private function seedDeliveries(Subscription $subscription, User $admin, int $position): void
    {
        $lastDate = $subscription->end_date->copy()->min(now()->subDay());
        for ($date = $subscription->start_date->copy(); $date <= $lastDate; $date->addDay()) {
            if ($date->isSunday() || \App\Models\Holiday::active()->whereDate('holiday_date', $date)->exists()) continue;
            foreach (['breakfast', 'lunch', 'dinner'] as $mealIndex => $meal) {
                if (! $subscription->{$meal}) continue;
                $selector = ($date->day + $position + $mealIndex) % 13;
                $status = match (true) { $selector === 0 => 'missed', $selector === 1 => 'skipped', $selector === 2 => 'pending', default => 'delivered' };
                Delivery::updateOrCreate([
                    'subscription_id' => $subscription->id,
                    'delivery_date' => $date->copy()->startOfDay(),
                    'meal_type' => $meal,
                ], [
                    'customer_id' => $subscription->customer_id,
                    'status' => $status,
                    'delivered_at' => $status === 'delivered' ? $date->copy()->setTime(8 + ($mealIndex * 5), 30) : null,
                    'notes' => $status === 'missed' ? '[DEMO-DATA] Customer was unavailable.' : null,
                    'updated_by' => $admin->id,
                ]);
            }
        }
    }

    private function seedRenewals($customers, User $admin): void
    {
        $dates = app(\App\Services\SubscriptionDateService::class);
        foreach ($customers as $index => $customer) {
            $previous = $customer->subscriptions()->where('subscription_no', 'like', 'DSUB-%')->oldest('id')->firstOrFail();
            foreach (range(1, 2) as $cycle) {
                $start = $previous->end_date->copy()->addDay();
                $new = Subscription::withTrashed()->updateOrCreate(['subscription_no' => 'DREN-'.$start->format('ym').'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)], [
                    'customer_id' => $customer->id, 'start_date' => $start, 'end_date' => $start->copy()->addDays(29),
                    'subscription_days' => 30, 'breakfast' => true, 'lunch' => true, 'dinner' => $index === 2,
                    'meal_count' => $index === 2 ? 3 : 2, 'package_type' => $index === 2 ? 'three_time' : 'two_time',
                    'amount' => $index === 2 ? 3700 : 2700, 'payment_status' => 'pending',
                    'status' => 'active', 'created_by' => $admin->id, 'deleted_at' => null,
                ]);
                SubscriptionRenewal::updateOrCreate(['new_subscription_id' => $new->id], [
                    'customer_id' => $customer->id, 'previous_subscription_id' => $previous->id,
                    'renewed_on' => $start, 'notes' => '[DEMO-DATA] Sample renewal cycle '.$cycle.'.', 'created_by' => $admin->id,
                ]);
                $new = $dates->recalculate($new);
                $this->seedPayment($new, $admin, $index + 1, $start->copy()->startOfMonth());
                $this->seedDeliveries($new, $admin, $index + 1);
                $previous = $new;
            }
            $customer->update(['status' => 'active']);
        }
    }

    private function seedExpenses(User $admin, array $months): void
    {
        $categories = ExpenseCategory::orderBy('id')->get();
        foreach ($months as $monthIndex => $month) {
            foreach (range(1, 24) as $position) {
                $category = $categories[($position + ($monthIndex * 5)) % $categories->count()];
                Expense::create([
                    'expense_date' => $month->copy()->addDays(($position * 3) % $month->daysInMonth),
                    'expense_category_id' => $category->id,
                    'amount' => 250 + (($position * 173 + $monthIndex * 89) % 4750),
                    'vendor_name' => ['Fresh Mart', 'City Wholesale', 'Green Farm', 'Metro Fuels', 'Daily Needs'][$position % 5],
                    'notes' => '[DEMO-DATA] Sample '.$category->name.' expense #'.$position.'.',
                    'created_by' => $admin->id,
                ]);
            }
        }
    }

    private function seedMealHolds(User $admin): void
    {
        $subscriptions = Subscription::where('subscription_no', 'like', 'DSUB-%')->where('meal_count', '>=', 2)->orderBy('id')->take(6)->get();
        foreach ($subscriptions as $index => $subscription) {
            $date = $subscription->start_date->copy()->addDays(6 + $index);
            while ($date->isSunday()) $date->addDay();
            $required = ['breakfast_required' => false, 'lunch_required' => false, 'dinner_required' => false];
            if ($index % 2 === 1) {
                $firstSelectedMeal = collect(['breakfast', 'lunch', 'dinner'])->first(fn ($meal) => $subscription->{$meal});
                $required[$firstSelectedMeal.'_required'] = true;
            }
            $hold = CustomerMealHold::updateOrCreate(['subscription_id' => $subscription->id, 'hold_date' => $date], $required + [
                'customer_id' => $subscription->customer_id,
                'is_full_day_hold' => $index % 2 === 0,
                'reason' => '[DEMO-DATA] '.($index % 2 === 0 ? 'Customer requested a full-day hold.' : 'Customer requested only one meal.'),
                'notes' => 'Sample meal-wise requirement for flow testing.',
                'created_by' => $admin->id,
            ]);
            foreach (['breakfast', 'lunch', 'dinner'] as $meal) if (! $hold->{$meal.'_required'}) Delivery::where('subscription_id', $subscription->id)->whereDate('delivery_date', $date)->where('meal_type', $meal)->delete();
        }
    }

    private function seedNotifications(User $admin, $customers): void
    {
        $admin->notify(new BusinessAlertNotification('Demo: pending payments', '[DEMO-DATA] Several sample customers have partial and pending balances.', route('payments.index')));
        $admin->notify(new BusinessAlertNotification('Demo: high expense alert', '[DEMO-DATA] Sample operating expenses crossed the review threshold.', route('expenses.index')));
        $admin->notify(new BusinessAlertNotification('Demo: subscription expiry', '[DEMO-DATA] '.$customers->first()->name.' has an expired sample subscription.', route('customers.show', $customers->first())));
        $admin->notify(new BusinessAlertNotification('Demo: monthly summary', '[DEMO-DATA] Previous-month revenue, expenses, and profit are ready for review.', route('reports.index', ['type' => 'profit'])));
    }
}
