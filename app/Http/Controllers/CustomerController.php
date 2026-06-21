<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Http\Requests\SubscriptionRequest;
use App\Models\Customer;
use App\Models\Setting;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(private CustomerRepositoryInterface $customers, private SubscriptionService $subscriptions) {}

    public function index(Request $request): View
    {
        return view('customers.index', ['customers' => $this->customers->paginate($request->only(['search', 'status', 'place'])), 'places' => Customer::whereNotNull('place')->distinct()->orderBy('place')->pluck('place')]);
    }

    public function create(): View { return view('customers.create', ['defaults' => $this->subscriptionDefaults()]); }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $subscription = validator($request->all(), (new SubscriptionRequest)->rules())->validate();
        $customer = DB::transaction(function () use ($request, $subscription) {
            $customer = $this->customers->create($request->validated() + ['customer_code' => $this->nextCode(), 'created_by' => $request->user()->id]);
            $this->subscriptions->create($customer, $subscription, $request->user()->id);
            return $customer;
        });
        return redirect()->route('customers.show', $customer)->with('success', 'Customer and subscription created.');
    }

    public function show(Customer $customer): View
    {
        $customer->load(['subscriptions.payments', 'payments', 'deliveries' => fn ($q) => $q->latest('delivery_date')->limit(30)]);
        return view('customers.show', compact('customer'));
    }

    public function edit(Customer $customer): View { return view('customers.edit', compact('customer')); }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customers->update($customer, $request->validated());
        return redirect()->route('customers.show', $customer)->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();
        return redirect()->route('customers.index')->with('success', 'Customer archived.');
    }

    public function renew(Customer $customer): View { return view('customers.renew', ['customer' => $customer, 'defaults' => $this->subscriptionDefaults()]); }

    public function storeRenewal(SubscriptionRequest $request, Customer $customer): RedirectResponse
    {
        $this->subscriptions->renew($customer, $request->validated(), $request->user()->id);
        return redirect()->route('customers.show', $customer)->with('success', 'Subscription renewed.');
    }

    public function pause(Customer $customer): RedirectResponse { $this->subscriptions->pause($customer); return back()->with('success', 'Subscription paused.'); }
    public function resume(Customer $customer): RedirectResponse { $this->subscriptions->resume($customer); return back()->with('success', 'Subscription resumed.'); }

    private function nextCode(): string
    {
        do { $code = 'CUS-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT); } while (Customer::where('customer_code', $code)->exists());
        return $code;
    }

    private function subscriptionDefaults(): array
    {
        return ['days' => Setting::value('default_subscription_days', 30), 'one' => Setting::value('one_time_package_price', 0), 'two' => Setting::value('two_time_package_price', 0), 'three' => Setting::value('three_time_package_price', 0)];
    }
}
