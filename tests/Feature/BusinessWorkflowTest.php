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
        $subscription = $this->subscription(1000);
        $service = app(PaymentService::class);
        $service->create(['subscription_id' => $subscription->id, 'payment_date' => today(), 'amount' => 400, 'method' => 'cash']);
        $this->assertSame('partial', $subscription->refresh()->payment_status);
        $this->assertSame(600.0, $subscription->outstanding_amount);

        $this->expectException(ValidationException::class);
        $service->create(['subscription_id' => $subscription->id, 'payment_date' => today(), 'amount' => 700, 'method' => 'cash']);
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

    private function customer(): Customer
    {
        return Customer::create(['customer_code' => 'CUS-000001', 'name' => 'Asha', 'gender' => 'female', 'primary_mobile' => '9000000000', 'primary_address' => 'Main Road', 'status' => 'active']);
    }

    private function subscription(float $amount = 1000): Subscription
    {
        return app(SubscriptionService::class)->create($this->customer(), ['start_date' => today(), 'subscription_days' => 30, 'breakfast' => true, 'lunch' => true, 'dinner' => false, 'amount' => $amount]);
    }
}
