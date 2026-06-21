<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function create(array $data, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($data, $userId) {
            if ($existing = Payment::where('transaction_token', $data['transaction_token'])->first()) return $existing;
            $subscription = Subscription::lockForUpdate()->findOrFail($data['subscription_id']);
            $packageAmount = $this->packageAmount($subscription);
            if ((float) $subscription->amount !== $packageAmount) $subscription->update(['amount' => $packageAmount]);
            $alreadyPaid = (float) $subscription->payments()->sum('amount');
            $balance = max(0, $packageAmount - $alreadyPaid);
            $newAmount = (float) $data['amount'];
            $newTotal = $alreadyPaid + $newAmount;

            if ($data['payment_type'] === 'full' && $newAmount < $balance) {
                throw ValidationException::withMessages(['amount' => 'Full payment must cover the complete remaining balance of '.Setting::value('currency', '₹').number_format($balance, 2).'.']);
            }
            if ($newTotal > $packageAmount && ! ($data['confirm_overpayment'] ?? false)) {
                throw ValidationException::withMessages(['amount' => 'Paid amount is greater than the package amount. Please confirm the overpayment to continue.']);
            }

            unset($data['confirm_overpayment']);
            $payment = Payment::create($data + ['customer_id' => $subscription->customer_id, 'receipt_no' => $this->nextReceipt(), 'created_by' => $userId]);
            $this->syncStatus($subscription);
            return $payment;
        });
    }

    public function syncStatus(Subscription $subscription): void
    {
        $paid = (float) $subscription->payments()->sum('amount');
        $amount = (float) $subscription->amount;
        $status = match (true) {
            $paid <= 0 => 'pending',
            $paid > $amount => 'overpaid',
            $paid >= $amount => 'paid',
            default => 'partial',
        };
        $subscription->update(['payment_status' => $status]);
    }

    public function packageAmount(Subscription $subscription): float
    {
        if ($subscription->payment_status === 'paid') return (float) $subscription->amount;
        $key = $subscription->package_type.'_package_price';
        $configured = (float) Setting::value($key, 0);
        return $configured > 0 ? $configured : (float) $subscription->amount;
    }

    public function summary(Subscription $subscription): array
    {
        $package = $this->packageAmount($subscription);
        $paid = isset($subscription->payments_sum_amount) ? (float) $subscription->payments_sum_amount : (float) $subscription->payments()->sum('amount');
        $balance = $package - $paid;
        $status = match (true) { $paid <= 0 => 'pending', $paid > $package => 'overpaid', $paid >= $package => 'paid', default => 'partial' };
        $expiredDays = today()->gt($subscription->end_date) ? (int) $subscription->end_date->diffInDays(today()) : 0;
        $visibleFrom = $subscription->end_date->copy()->subDays((int) Setting::value('expiry_alert_days', 7));
        return [
            'id' => $subscription->id, 'customer' => $subscription->customer->name, 'mobile' => $subscription->customer->primary_mobile,
            'package_type' => str($subscription->package_type)->replace('_', ' ')->title()->toString(), 'meals' => $subscription->meal_names,
            'start_date' => $subscription->start_date->format('d-m-Y'), 'original_end_date' => ($subscription->original_end_date ?? $subscription->end_date)->format('d-m-Y'),
            'end_date' => $subscription->end_date->format('d-m-Y'), 'package_amount' => $package, 'paid_amount' => $paid,
            'balance_amount' => (float) max(0, $balance), 'overpaid_amount' => (float) max(0, -$balance), 'payment_status' => $status,
            'expired_days' => $expiredDays, 'is_expired' => $expiredDays > 0, 'transactions' => isset($subscription->payments_count) ? $subscription->payments_count : $subscription->payments()->count(),
            'visible_from' => $visibleFrom->format('d-m-Y'),
            'last_payment_date' => $subscription->payments_max_payment_date ? \Carbon\Carbon::parse($subscription->payments_max_payment_date)->format('d-m-Y') : null,
        ];
    }

    public function eligibleSubscriptions(bool $showPaid = false): Collection
    {
        $alertDays = (int) Setting::value('expiry_alert_days', 7);
        return Subscription::with('customer')->withSum('payments', 'amount')->withCount('payments')->withMax('payments', 'payment_date')
            ->whereIn('status', ['active', 'expired', 'paused'])
            ->when(! $showPaid, fn ($query) => $query->whereIn('payment_status', ['pending', 'partial']))
            ->where(function ($query) use ($showPaid, $alertDays) {
                $query->whereIn('payment_status', ['pending', 'partial'])
                    ->orWhere('status', 'expired')
                    ->orWhereDate('end_date', '<=', today()->addDays($alertDays));
                if ($showPaid) $query->orWhere('payment_status', 'paid');
            })
            ->orderByRaw("CASE WHEN status = 'expired' THEN 0 WHEN payment_status = 'partial' THEN 1 ELSE 2 END")
            ->orderBy('end_date')->get();
    }

    public function customerDueBreakdown(Customer|int $customer): array
    {
        $customerId = $customer instanceof Customer ? $customer->id : $customer;
        $subscriptions = Subscription::with('customer')->withSum('payments', 'amount')->withCount('payments')->withMax('payments', 'payment_date')->where('customer_id', $customerId)->orderBy('start_date')->get();
        $latestId = $subscriptions->last()?->id;
        $rows = $subscriptions->map(function ($subscription) use ($latestId) {
            $summary = $this->summary($subscription);
            return ['id' => $subscription->id, 'subscription_no' => $subscription->subscription_no, 'period' => $summary['start_date'].' – '.$summary['end_date'], 'package_type' => $summary['package_type'], 'package_amount' => $summary['package_amount'], 'paid_amount' => $summary['paid_amount'], 'balance_amount' => $summary['balance_amount'], 'payment_status' => $summary['payment_status'], 'is_latest' => $subscription->id === $latestId];
        })->filter(fn ($row) => $row['balance_amount'] > 0 || $row['is_latest'])->values();
        return ['rows' => $rows, 'total_payable' => (float) $rows->sum('balance_amount'), 'latest_subscription_id' => $latestId];
    }

    public function syncOutstandingPackagePrices(): int
    {
        $count = 0;
        Subscription::whereIn('payment_status', ['pending', 'partial', 'overpaid'])->each(function ($subscription) use (&$count) {
            $amount = $this->packageAmount($subscription);
            if ((float) $subscription->amount !== $amount) { $subscription->update(['amount' => $amount]); $count++; }
            $this->syncStatus($subscription);
        });
        return $count;
    }

    private function nextReceipt(): string
    {
        do { $number = 'PAY-'.now()->format('ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT); }
        while (Payment::where('receipt_no', $number)->exists());
        return $number;
    }
}
