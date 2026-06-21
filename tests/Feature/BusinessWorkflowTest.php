<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Holiday;
use App\Models\CustomerMealHold;
use App\Services\DeliveryService;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Services\SubscriptionDateService;
use App\Services\MealHoldService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BusinessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_subscription_derives_package_dates_and_meals(): void
    {
        $this->assertSame('2026-07-20', app(SubscriptionDateService::class)->calculateOriginalEndDate('2026-06-21', 30)->toDateString());
        $customer = $this->customer();
        $subscription = app(SubscriptionService::class)->create($customer, [
            'start_date' => '2026-06-01', 'subscription_days' => 30,
            'breakfast' => true, 'lunch' => true, 'dinner' => false, 'amount' => 3000,
        ]);

        $this->assertSame('two_time', $subscription->package_type);
        $this->assertSame(2, $subscription->meal_count);
        $this->assertSame('2026-06-30', $subscription->original_end_date->toDateString());
        $this->assertSame('2026-07-04', $subscription->end_date->toDateString());
        $this->assertSame(4, $subscription->holiday_compensation_days);
        $this->assertSame('Breakfast, Lunch', $subscription->meal_names);
    }

    public function test_payment_updates_balance_status_and_rejects_overpayment(): void
    {
        $subscription = $this->subscription(2700);
        $service = app(PaymentService::class);
        $service->create($this->paymentData($subscription, 400));
        $this->assertSame('partial', $subscription->refresh()->payment_status);
        $this->assertSame(2300.0, $service->summary($subscription)['balance_amount']);

        $this->expectException(ValidationException::class);
        $service->create($this->paymentData($subscription, 2400));
    }

    public function test_full_payment_saves_transaction_and_updates_dashboard_collection(): void
    {
        Carbon::setTestNow('2026-06-22 10:00:00');
        $subscription = $this->threeMealSubscription();
        $payment = app(PaymentService::class)->create($this->paymentData($subscription, 3700, 'full'));
        $this->assertSame('paid', $subscription->refresh()->payment_status);
        $this->assertSame(0.0, app(PaymentService::class)->summary($subscription)['balance_amount']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 3700, 'payment_type' => 'full']);
        $this->assertSame(3700.0, app(\App\Services\DashboardService::class)->data([])['finance']['today_collection']);
    }

    public function test_multiple_partial_payments_and_final_balance_remain_separate_transactions(): void
    {
        $subscription = $this->threeMealSubscription(); $service = app(PaymentService::class);
        $service->create($this->paymentData($subscription, 1000));
        $service->create($this->paymentData($subscription, 1500));
        $summary = $service->summary($subscription->refresh());
        $this->assertSame(2500.0, $summary['paid_amount']); $this->assertSame(1200.0, $summary['balance_amount']); $this->assertSame('partial', $subscription->payment_status);
        $service->create($this->paymentData($subscription, 1200, 'full'));
        $this->assertSame('paid', $subscription->refresh()->payment_status);
        $this->assertDatabaseCount('payments', 3);
        $this->assertSame([1000.0, 1500.0, 1200.0], $subscription->payments()->orderBy('id')->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
    }

    public function test_expired_unpaid_summary_shows_full_settings_package_and_expired_days(): void
    {
        Carbon::setTestNow('2026-08-01 10:00:00');
        $subscription = $this->threeMealSubscription('2026-06-01');
        $summary = app(PaymentService::class)->summary($subscription);
        $this->assertSame(3700.0, $summary['package_amount']); $this->assertSame(0.0, $summary['paid_amount']); $this->assertSame(3700.0, $summary['balance_amount']);
        $this->assertTrue($summary['is_expired']); $this->assertGreaterThan(0, $summary['expired_days']); $this->assertSame('pending', $summary['payment_status']);
    }

    public function test_expiry_window_and_paid_subscription_visibility_are_correct(): void
    {
        Carbon::setTestNow('2026-06-22 10:00:00');
        $subscription = $this->threeMealSubscription();
        $subscription->update(['end_date' => '2026-06-30', 'original_end_date' => '2026-06-30']);
        $this->assertSame('23-06-2026', app(PaymentService::class)->summary($subscription)['visible_from']);
        $this->assertTrue(app(PaymentService::class)->eligibleSubscriptions()->contains('id', $subscription->id));
        app(PaymentService::class)->create($this->paymentData($subscription, 3700, 'full'));
        $this->assertFalse(app(PaymentService::class)->eligibleSubscriptions()->contains('id', $subscription->id));
        $this->assertTrue(app(PaymentService::class)->eligibleSubscriptions(true)->contains('id', $subscription->id));
    }

    public function test_duplicate_submission_token_creates_one_transaction_and_confirmed_overpayment_is_tracked(): void
    {
        $subscription = $this->threeMealSubscription(); $service = app(PaymentService::class);
        $data = $this->paymentData($subscription, 1000);
        $first = $service->create($data); $second = $service->create($data);
        $this->assertSame($first->id, $second->id); $this->assertDatabaseCount('payments', 1);
        $service->create($this->paymentData($subscription, 2800, 'full') + ['confirm_overpayment' => true]);
        $this->assertSame('overpaid', $subscription->refresh()->payment_status);
        $this->assertSame(100.0, $service->summary($subscription)['overpaid_amount']);
    }

    public function test_food_after_final_end_requires_a_new_subscription(): void
    {
        Carbon::setTestNow('2026-07-06 10:00:00');
        $customer = $this->customer();
        $old = app(SubscriptionService::class)->create($customer, [
            'start_date' => '2026-06-01', 'subscription_days' => 30,
            'breakfast' => true, 'lunch' => true, 'dinner' => false, 'amount' => 2700,
        ]);

        $this->assertSame('2026-07-04', $old->end_date->toDateString());
        $this->assertSame(0, app(DeliveryService::class)->generateForDate(today()));
        $this->assertDatabaseMissing('deliveries', ['subscription_id' => $old->id, 'delivery_date' => today()]);

        $renewal = app(SubscriptionService::class)->renew($customer, [
            'start_date' => today(), 'subscription_days' => 30,
            'breakfast' => true, 'lunch' => true, 'dinner' => false, 'amount' => 2700,
        ]);
        $this->assertSame(2, app(DeliveryService::class)->generateForDate(today()));
        $this->assertDatabaseCount('deliveries', 2);
        $this->assertSame([$renewal->id], $customer->deliveries()->whereDate('delivery_date', today())->pluck('subscription_id')->unique()->values()->all());
        $this->assertDatabaseMissing('deliveries', ['subscription_id' => $old->id, 'delivery_date' => today()]);
    }

    public function test_old_and_new_subscription_dues_are_kept_separate(): void
    {
        Carbon::setTestNow('2026-07-06 10:00:00');
        $customer = $this->customer();
        $old = app(SubscriptionService::class)->create($customer, [
            'start_date' => '2026-06-01', 'subscription_days' => 30,
            'breakfast' => true, 'lunch' => true, 'dinner' => false, 'amount' => 2700,
        ]);
        $payments = app(PaymentService::class);
        $payments->create($this->paymentData($old, 1440));
        $renewal = app(SubscriptionService::class)->renew($customer, [
            'start_date' => today(), 'subscription_days' => 30,
            'breakfast' => true, 'lunch' => true, 'dinner' => false, 'amount' => 2700,
        ]);

        $breakdown = $payments->customerDueBreakdown($customer);
        $oldDue = collect($breakdown['rows'])->firstWhere('id', $old->id);
        $newDue = collect($breakdown['rows'])->firstWhere('id', $renewal->id);

        $this->assertSame(1260.0, $oldDue['balance_amount']);
        $this->assertSame(2700.0, $newDue['package_amount']);
        $this->assertSame(2700.0, $newDue['balance_amount']);
        $this->assertSame(3960.0, $breakdown['total_payable']);
        $this->assertSame($old->id, Payment::sole()->subscription_id);
        $this->assertDatabaseMissing('payments', ['subscription_id' => $renewal->id]);
    }

    public function test_renewal_cannot_overlap_the_previous_final_end_date(): void
    {
        $customer = $this->customer();
        $old = app(SubscriptionService::class)->create($customer, [
            'start_date' => '2026-06-01', 'subscription_days' => 30,
            'breakfast' => true, 'lunch' => true, 'dinner' => false, 'amount' => 2700,
        ]);

        $this->expectException(ValidationException::class);
        app(SubscriptionService::class)->renew($customer, [
            'start_date' => $old->end_date, 'subscription_days' => 30,
            'breakfast' => true, 'lunch' => true, 'dinner' => false, 'amount' => 2700,
        ]);
    }

    public function test_delivery_generation_is_idempotent_and_meal_aware(): void
    {
        Carbon::setTestNow('2026-06-22 10:00:00');
        $subscription = $this->subscription();
        app(DeliveryService::class)->generateForDate(today());
        app(DeliveryService::class)->generateForDate(today());
        $this->assertDatabaseCount('deliveries', 2);
        $this->assertDatabaseHas('deliveries', ['subscription_id' => $subscription->id, 'meal_type' => 'breakfast']);
        $this->assertDatabaseHas('deliveries', ['subscription_id' => $subscription->id, 'meal_type' => 'lunch']);
    }

    public function test_holiday_removes_pending_deliveries_and_dashboard_reports_zero(): void
    {
        Carbon::setTestNow('2026-06-21 10:00:00');
        $subscription = $this->subscription();
        \App\Models\Delivery::create(['customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id, 'delivery_date' => today(), 'meal_type' => 'breakfast', 'status' => 'pending']);
        $this->assertSame(0, app(DeliveryService::class)->generateForDate(today()));
        $this->assertDatabaseCount('deliveries', 0);
        $dashboard = app(\App\Services\DashboardService::class)->data(['date' => today()->toDateString()]);
        $this->assertTrue($dashboard['holiday']['is_holiday']);
        $this->assertSame(0, $dashboard['cards']['deliveries_today']);
    }

    public function test_extra_holidays_and_full_day_holds_extend_service_days_iteratively(): void
    {
        $subscription = app(SubscriptionService::class)->create($this->customer(), ['start_date' => '2026-06-01', 'subscription_days' => 30, 'breakfast' => true, 'lunch' => true, 'dinner' => false, 'amount' => 1000]);
        Holiday::create(['holiday_date' => '2026-06-02', 'title' => 'Emergency closure', 'type' => 'emergency', 'status' => 'active']);
        $dates = app(SubscriptionDateService::class);
        $subscription = $dates->recalculate($subscription);
        $this->assertSame('2026-07-06', $subscription->end_date->toDateString());

        $hold = CustomerMealHold::create(['customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id, 'hold_date' => '2026-06-03', 'breakfast_required' => false, 'lunch_required' => false, 'dinner_required' => false, 'is_full_day_hold' => true]);
        $subscription = $dates->recalculate($subscription);
        $this->assertSame('2026-07-07', $subscription->end_date->toDateString());
        $this->assertSame(6, $subscription->holiday_compensation_days);
        $this->assertSame(1, $subscription->meal_hold_compensation_days);
        $this->assertDatabaseHas('subscription_compensations', ['subscription_id' => $subscription->id, 'compensation_date' => '2026-06-03 00:00:00', 'compensation_type' => 'meal_hold']);
    }

    public function test_partial_hold_generates_only_the_required_meal_without_compensation(): void
    {
        Carbon::setTestNow('2026-06-22 10:00:00');
        $subscription = $this->subscription();
        CustomerMealHold::create(['customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id, 'hold_date' => today(), 'breakfast_required' => false, 'lunch_required' => true, 'dinner_required' => false, 'is_full_day_hold' => false]);
        app(SubscriptionDateService::class)->recalculate($subscription);
        app(DeliveryService::class)->generateForDate(today());
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseHas('deliveries', ['subscription_id' => $subscription->id, 'meal_type' => 'lunch']);
        $this->assertDatabaseMissing('deliveries', ['subscription_id' => $subscription->id, 'meal_type' => 'breakfast']);
        $this->assertSame(0, $subscription->refresh()->meal_hold_compensation_days);
    }

    public function test_date_range_holds_create_each_date_and_update_duplicates(): void
    {
        Carbon::setTestNow('2026-06-22 10:00:00');
        $subscription = $this->subscription();
        $service = app(MealHoldService::class);
        $partial = $service->createDateRangeHold($subscription, '2026-06-23', '2026-06-26', ['breakfast' => 'not_required', 'lunch' => 'required', 'dinner' => null], ['reason' => 'Travel']);
        $this->assertSame(4, $partial['created']);
        $this->assertSame(0, $partial['updated']);
        $this->assertSame(0, $partial['compensation_days']);
        $this->assertDatabaseCount('customer_meal_holds', 4);
        $this->assertDatabaseCount('deliveries', 4);
        $this->assertDatabaseMissing('deliveries', ['subscription_id' => $subscription->id, 'meal_type' => 'breakfast']);

        $full = $service->createDateRangeHold($subscription, '2026-06-23', '2026-06-26', ['breakfast' => 'not_required', 'lunch' => 'not_required', 'dinner' => null], ['reason' => 'Trip extended']);
        $this->assertSame(0, $full['created']);
        $this->assertSame(4, $full['updated']);
        $this->assertSame(4, $full['full_hold_days']);
        $this->assertDatabaseCount('customer_meal_holds', 4);
        $this->assertSame(4, $subscription->refresh()->meal_hold_compensation_days);
    }

    public function test_admin_can_open_every_primary_operational_screen(): void
    {
        $this->actingAs(User::where('email', 'admin@goldenmess.test')->firstOrFail());
        foreach (['/', '/customers', '/deliveries', '/payments', '/expenses', '/holidays', '/meal-holds', '/settings', '/reports', '/reports/holidays', '/reports/meal-holds', '/reports/compensations', '/reports/extensions', '/reports/meal-not-required', '/notifications', '/search?q=asha'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_reports_export_as_native_excel_and_pdf_files(): void
    {
        $this->actingAs(User::where('email', 'admin@goldenmess.test')->firstOrFail());
        $this->get('/reports/customers?format=excel')->assertOk()->assertHeader('content-disposition');
        $this->get('/reports/customers?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_dashboard_summary_can_refresh_over_ajax(): void
    {
        $this->actingAs(User::where('email', 'admin@goldenmess.test')->firstOrFail());
        $this->getJson('/dashboard/summary')->assertOk()->assertJsonStructure([
            'cards' => ['active_customers', 'deliveries_today', 'monthly_revenue', 'monthly_expenses', 'monthly_profit', 'outstanding'],
            'deliveryStats' => ['breakfast', 'lunch', 'dinner'], 'finance', 'alerts', 'refreshed_at',
        ]);
    }

    public function test_customer_profile_renders_grouped_delivery_days(): void
    {
        Carbon::setTestNow('2026-06-22 10:00:00');
        $subscription = $this->subscription();
        app(DeliveryService::class)->generateForDate(today());
        $this->actingAs(User::where('email', 'admin@goldenmess.test')->firstOrFail());
        $this->get(route('customers.show', $subscription->customer_id))
            ->assertOk()->assertSee('Recent deliveries')->assertSee('Breakfast')->assertSee('Lunch')->assertSee('Dinner');
    }

    private function customer(): Customer
    {
        return Customer::create(['customer_code' => 'CUS-000001', 'name' => 'Asha', 'gender' => 'female', 'primary_mobile' => '9000000000', 'primary_address' => 'Main Road', 'status' => 'active']);
    }

    private function subscription(float $amount = 1000): Subscription
    {
        return app(SubscriptionService::class)->create($this->customer(), ['start_date' => today(), 'subscription_days' => 30, 'breakfast' => true, 'lunch' => true, 'dinner' => false, 'amount' => $amount]);
    }

    private function threeMealSubscription(string|\Carbon\CarbonInterface|null $start = null): Subscription
    {
        return app(SubscriptionService::class)->create($this->customer(), ['start_date' => $start ?? today(), 'subscription_days' => 30, 'breakfast' => true, 'lunch' => true, 'dinner' => true, 'amount' => 3700]);
    }

    private function paymentData(Subscription $subscription, float $amount, string $type = 'partial'): array
    {
        return ['transaction_token' => (string) \Illuminate\Support\Str::uuid(), 'subscription_id' => $subscription->id, 'payment_date' => today(), 'amount' => $amount, 'method' => 'cash', 'payment_type' => $type];
    }
}
