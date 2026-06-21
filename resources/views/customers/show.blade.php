@extends('layouts.app')

@section('title', $customer->name)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <a href="{{ route('customers.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Customers</a>
        <div class="d-flex align-items-center gap-3 mt-2">
            <span class="avatar" style="width:54px;height:54px;font-size:1.2rem">{{ strtoupper(substr($customer->name, 0, 1)) }}</span>
            <div><h1 class="page-title mb-1">{{ $customer->name }}</h1><span class="text-muted">{{ $customer->customer_code }}</span> <span class="badge status-{{ $customer->status }}">{{ ucfirst($customer->status) }}</span></div>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('customers.edit', $customer) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i>Edit</a>
        <a href="{{ route('customers.renew', $customer) }}" class="btn btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Renew</a>
        @if($customer->status === 'paused')
            <form method="post" action="{{ route('customers.resume', $customer) }}">@csrf @method('patch')<button class="btn btn-success">Resume</button></form>
        @elseif($customer->status === 'active')
            <form method="post" action="{{ route('customers.pause', $customer) }}">@csrf @method('patch')<button class="btn btn-warning">Pause</button></form>
        @endif
        <form method="post" action="{{ route('customers.destroy', $customer) }}" data-confirm="Archive this customer?">@csrf @method('delete')<button class="btn btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-5"><div class="card h-100"><div class="card-body">
        <div class="section-title mb-3">Contact & delivery</div>
        <dl class="row mb-0">
            <dt class="col-5 text-muted fw-normal">Mobile</dt><dd class="col-7 fw-semibold">{{ $customer->primary_mobile }}</dd>
            <dt class="col-5 text-muted fw-normal">Secondary</dt><dd class="col-7">{{ $customer->secondary_mobile ?: '—' }}</dd>
            <dt class="col-5 text-muted fw-normal">Area</dt><dd class="col-7">{{ $customer->place ?: '—' }}</dd>
            <dt class="col-5 text-muted fw-normal">Address</dt><dd class="col-7">{{ $customer->primary_address }}</dd>
            <dt class="col-5 text-muted fw-normal">Landmark</dt><dd class="col-7">{{ $customer->landmark ?: '—' }}</dd>
        </dl>
        @if($customer->google_map_url)<a target="_blank" href="{{ $customer->google_map_url }}" class="btn btn-sm btn-light"><i class="bi bi-geo-alt"></i> Open map</a>@endif
    </div></div></div>
    <div class="col-lg-7"><div class="card h-100"><div class="card-body">
        <div class="section-title mb-3">Current subscription</div>
        @if($currentSubscription)
            <div class="row g-3">
                <div class="col-sm-4"><div class="small text-muted">Package</div><div class="fw-bold fs-5">{{ str($currentSubscription->package_type)->replace('_', ' ')->title() }}</div><div>{{ $currentSubscription->meal_names }}</div></div>
                <div class="col-sm-4"><div class="small text-muted">Duration</div><div class="fw-bold">{{ $currentSubscription->start_date->format('d M Y') }}</div><div>to {{ $currentSubscription->end_date->format('d M Y') }}</div></div>
                <div class="col-sm-4"><div class="small text-muted">Payment</div><div class="fw-bold fs-5">{{ \App\Models\Setting::value('currency', '₹') }}{{ number_format($currentSubscription->amount, 2) }}</div><span class="badge status-{{ $currentSubscription->payment_status }}">{{ ucfirst($currentSubscription->payment_status) }}</span> <span class="small">Due {{ number_format($currentSubscription->outstanding_amount, 2) }}</span></div>
            </div>
        @else
            <div class="empty-state">No subscription found.</div>
        @endif
    </div></div></div>
</div>

@if($subscriptionMetrics)
<div class="card mb-4"><div class="card-body">
    <div class="section-title mb-3">Service-day entitlement</div>
    <div class="row g-3">
        @foreach([
            ['Start date', $currentSubscription->start_date->format('d M Y'), 'bg-light'],
            ['Original end date', $subscriptionMetrics['original_end_date']->format('d M Y'), 'bg-light'],
            ['Holiday compensation', $subscriptionMetrics['holiday_days'].' days', 'bg-light'],
            ['Meal hold compensation', $subscriptionMetrics['meal_hold_days'].' days', 'bg-light'],
            ['Extended end date', $subscriptionMetrics['final_end_date']->format('d M Y'), 'bg-warning-subtle'],
            ['Used / remaining', $subscriptionMetrics['used_service_days'].' / '.$subscriptionMetrics['remaining_service_days'], 'bg-light'],
        ] as [$label, $value, $background])
            <div class="col-6 col-md"><div class="p-3 rounded-3 {{ $background }}"><div class="small text-muted">{{ $label }}</div><div class="fw-bold">{{ $value }}</div></div></div>
        @endforeach
    </div>
</div></div>
@endif

<div class="card mb-4"><div class="card-body">
    <div class="section-title mb-3">Subscription history</div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Number</th><th>Original dates</th><th>Extended end</th><th>Compensation</th><th>Meals</th><th class="text-end">Package</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Payment</th><th>Status</th></tr></thead><tbody>
    @foreach($customer->subscriptions->sortByDesc('id') as $subscription)
        @php $subscriptionPaid = (float) $subscription->payments->sum('amount'); $subscriptionBalance = max(0, (float) $subscription->amount - $subscriptionPaid); @endphp
        <tr><td class="fw-semibold">{{ $subscription->subscription_no }}</td><td>{{ $subscription->start_date->format('d M Y') }} — {{ ($subscription->original_end_date ?? $subscription->end_date)->format('d M Y') }}</td><td>{{ $subscription->end_date->format('d M Y') }}</td><td>{{ $subscription->holiday_compensation_days + $subscription->meal_hold_compensation_days }} days</td><td>{{ $subscription->meal_names }}</td><td class="text-end">{{ number_format($subscription->amount, 2) }}</td><td class="text-end text-success">{{ number_format($subscriptionPaid, 2) }}</td><td class="text-end fw-semibold {{ $subscriptionBalance > 0 ? 'text-danger' : 'text-success' }}">{{ number_format($subscriptionBalance, 2) }}</td><td><span class="badge status-{{ $subscription->payment_status }}">{{ ucfirst($subscription->payment_status) }}</span></td><td><span class="badge status-{{ $subscription->status }}">{{ ucfirst($subscription->status) }}</span></td></tr>
    @endforeach
    </tbody></table></div>
</div></div>

<div class="row g-4">
    <div class="col-lg-6"><div class="card"><div class="card-body">
        <div class="section-title mb-3">Payment ledger</div>
        <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Receipt</th><th>Method</th><th class="text-end">Amount</th></tr></thead><tbody>
        @forelse($customer->payments->sortByDesc('payment_date') as $payment)
            <tr><td>{{ $payment->payment_date->format('d M Y') }}</td><td>{{ $payment->receipt_no }}</td><td>{{ strtoupper($payment->method) }}</td><td class="text-end fw-bold">{{ number_format($payment->amount, 2) }}</td></tr>
        @empty
            <tr><td colspan="4" class="empty-state">No payments recorded.</td></tr>
        @endforelse
        </tbody></table></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-body">
        <div class="section-title mb-3">Recent deliveries</div>
        <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Breakfast</th><th>Lunch</th><th>Dinner</th></tr></thead><tbody>
        @forelse($recentDeliveryDays as $date => $dayDeliveries)
            @php
                $meals = $dayDeliveries->keyBy('meal_type');
            @endphp
            <tr><td class="fw-semibold text-nowrap">{{ \Carbon\Carbon::parse($date)->format('d M Y') }}</td>
                @foreach(['breakfast', 'lunch', 'dinner'] as $meal)
                    <td>@if($meals->has($meal))<span class="badge status-{{ $meals[$meal]->status }}">{{ ucfirst($meals[$meal]->status) }}</span>@else<span class="text-muted">—</span>@endif</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="4" class="empty-state">No delivery history.</td></tr>
        @endforelse
        </tbody></table></div>
    </div></div></div>
</div>
@endsection
