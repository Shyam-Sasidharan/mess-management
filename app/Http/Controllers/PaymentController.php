<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentRequest;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function index(Request $request, PaymentService $service): View
    {
        $payments = Payment::with(['customer', 'subscription', 'creator'])->when($request->search, fn ($q, $term) => $q->whereHas('customer', fn ($q) => $q->search($term)))->latest('payment_date')->paginate(20)->withQueryString();
        $subscriptions = $service->eligibleSubscriptions($request->boolean('show_paid'));
        $customerBreakdowns = $subscriptions->pluck('customer_id')->unique()->mapWithKeys(fn ($customerId) => [$customerId => $service->customerDueBreakdown($customerId)]);
        $subscriptionSummaries = $subscriptions->mapWithKeys(function ($subscription) use ($service, $customerBreakdowns) { $summary = $service->summary($subscription); $summary['account_breakdown'] = $customerBreakdowns[$subscription->customer_id]; return [$subscription->id => $summary]; });
        return view('payments.index', ['payments' => $payments, 'subscriptions' => $subscriptions, 'subscriptionSummaries' => $subscriptionSummaries, 'transactionToken' => (string) Str::uuid()]);
    }
    public function store(PaymentRequest $request, PaymentService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user()->id);
        return back()->with('success', 'Payment recorded.');
    }
}
