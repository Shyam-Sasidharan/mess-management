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
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    public function index(Request $request, PaymentService $service): View
    {
        $payments = Payment::with(['customer', 'subscription', 'creator'])->when($request->search, fn ($q, $term) => $q->whereHas('customer', fn ($q) => $q->search($term)))->latest('payment_date')->paginate(20)->withQueryString();
        $subscriptions = $service->eligibleSubscriptions($request->boolean('show_paid'));
        $customerBreakdowns = $subscriptions->pluck('customer_id')->unique()->mapWithKeys(fn ($customerId) => [$customerId => $service->customerDueBreakdown($customerId)]);
        $subscriptionSummaries = $subscriptions->mapWithKeys(function ($subscription) use ($service, $customerBreakdowns) { $summary = $service->summary($subscription); $summary['account_breakdown'] = $customerBreakdowns[$subscription->customer_id]; return [$subscription->id => $summary]; });
        $paidSubscriptions = $request->boolean('show_paid') ? $subscriptions->filter(fn ($subscription) => $subscriptionSummaries[$subscription->id]['payment_status'] === 'paid')->values() : collect();
        return view('payments.index', ['payments' => $payments, 'subscriptions' => $subscriptions, 'subscriptionSummaries' => $subscriptionSummaries, 'paidSubscriptions' => $paidSubscriptions, 'transactionToken' => (string) Str::uuid()]);
    }
    public function store(PaymentRequest $request, PaymentService $service): RedirectResponse
    {
        $data = $request->validated();
        if ($data['payment_type'] === 'full') {
            $payments = $service->createCustomerFullPayment($data, $request->user()->id);
            return back()->with('success', 'Payment saved successfully. Full amount allocated across '.$payments->count().' subscription(s).')->with('receipt_ids', $payments->pluck('id')->all());
        }
        $payment = $service->create($data, $request->user()->id);
        return back()->with('success', 'Payment saved successfully.')->with('receipt_ids', [$payment->id]);
    }

    public function receipt(Payment $payment): View { return view('payments.receipt', $this->receiptData($payment) + ['autoPrint' => false, 'pdf' => false]); }
    public function printReceipt(Payment $payment): View { return view('payments.receipt', $this->receiptData($payment) + ['autoPrint' => true, 'pdf' => false]); }
    public function receiptPdf(Payment $payment): mixed
    {
        $data = $this->receiptData($payment) + ['autoPrint' => false, 'pdf' => true];
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('payments.receipt', $data)->setPaper('a4')->download($payment->receipt_no.'.pdf');
    }

    private function receiptData(Payment $payment): array
    {
        $payment->load(['customer', 'subscription']);
        $previousPaid = (float) Payment::where('subscription_id', $payment->subscription_id)->where(function ($query) use ($payment) {
            $query->whereDate('payment_date', '<', $payment->payment_date)->orWhere(function ($query) use ($payment) { $query->whereDate('payment_date', $payment->payment_date)->where('id', '<', $payment->id); });
        })->sum('amount');
        $packageAmount = (float) ($payment->subscription?->amount ?? 0);
        $totalPaid = $previousPaid + (float) $payment->amount;
        $logo = Setting::value('business_logo');
        return [
            'payment' => $payment, 'currency' => Setting::value('currency', '₹'), 'previousPaid' => $previousPaid,
            'packageAmount' => $packageAmount, 'totalPaid' => $totalPaid, 'balance' => max(0, $packageAmount - $totalPaid),
            'paymentStatus' => $totalPaid >= $packageAmount ? 'Paid' : ($totalPaid > 0 ? 'Partial' : 'Pending'),
            'business' => ['name' => Setting::value('business_name', 'Golden Mess'), 'mobile' => Setting::value('business_mobile'), 'address' => Setting::value('business_address')],
            'logoPath' => $logo && Storage::disk('public')->exists($logo) ? Storage::disk('public')->path($logo) : null,
            'logoWebUrl' => $logo && Storage::disk('public')->exists($logo) ? Storage::disk('public')->url($logo) : null,
        ];
    }
}
