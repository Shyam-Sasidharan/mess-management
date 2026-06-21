<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentRequest;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $payments = Payment::with(['customer', 'subscription'])->when($request->search, fn ($q, $term) => $q->whereHas('customer', fn ($q) => $q->search($term)))->latest('payment_date')->paginate(20)->withQueryString();
        $subscriptions = Subscription::with('customer')->whereIn('payment_status', ['pending', 'partial'])->latest()->get();
        return view('payments.index', compact('payments', 'subscriptions'));
    }
    public function store(PaymentRequest $request, PaymentService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user()->id);
        return back()->with('success', 'Payment recorded.');
    }
    public function update(PaymentRequest $request, Payment $payment, PaymentService $service): RedirectResponse
    {
        $service->update($payment, $request->validated());
        return back()->with('success', 'Payment updated.');
    }
    public function destroy(Payment $payment, PaymentService $service): RedirectResponse
    {
        $subscription = $payment->subscription;
        $payment->delete();
        if ($subscription) $service->syncStatus($subscription);
        return back()->with('success', 'Payment removed.');
    }
}
